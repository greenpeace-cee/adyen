<?php
namespace Civi\Api4\Action\ContributionRecur;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Contribution;
use Civi\Api4\Generic\Result;
use Civi\Core\Event\GenericHookEvent;
use CRM_Core_Exception;
use CRM_Core_Lock;
use CRM_Core_Payment_Adyen;
use CRM_Core_Transaction;
use CRM_Contribute_BAO_ContributionRecur;
use CRM_Contribute_BAO_Contribution;

class ProcessAdyen extends \Civi\Api4\Generic\AbstractAction
{
  /**
   *
   */
  public function _run(Result $result) {

    // Try to get a server-wide lock for this process, be patient for 90s.
    $lock = new CRM_Core_Lock('worker.contribute.processadyen', 90, TRUE);
    $lock->acquire();
    if (!$lock->isAcquired()) {
      throw new CRM_Core_Exception("Failed to get a lock to process Adyen contributions. Try later.");
    }

    try {
      // todo can we do this or do we need to return an array of arrays?
      $result['newPending'] = $this->generatePendingContributions();
      $result['contributionsProcessed'] = $this->processPendingContributions();
    }
    catch (\Exception $e) {
      $lock->release();
      throw $e;
    }

    $lock->release();
  }

  /**
   * Generate Pending contributions from Adyen ContributionRecur records.
   *
   * @return array
   *    ...of created contribution IDs, keyed by ContributionRecur ID.
   *    Special case: A contribution ID of zero means one needs to be created
   *    but is waiting on another Pending Contribution.
   */
  public function generatePendingContributions(): array {
    $dueRecurs = ContributionRecur::get(FALSE)
      ->addWhere('payment_processor_id.payment_processor_type_id:name', '=', 'Adyen')
      ->addWhere('payment_processor_id.is_active', '=', true)
      ->addWhere('next_sched_contribution_date', '<=', 'today')
      ->addWhere('is_test', 'IN', [0, 1])
      ->addClause(
        'OR',
        ['contribution_status_id:name', '=', 'In Progress'],
        ['AND',
          [
            ['contribution_status_id:name', 'IN', ['Failing', 'Overdue']],
            ['failure_retry_date', '<=', 'today']
          ]
        ])
      // ->setDebug(TRUE)
      ->execute();
    // print_r($dueRecurs->debug);
    $results = [];

    foreach ($dueRecurs as $recur) {
      // Use a transaction to ensure that the next_sched_contribution_date is only updated
      // on successful creation of a Contribution. That way we don’t miss a contribution if
      // there is a problem creating one.
      CRM_Core_Transaction::create()->run(function() use ($recur, &$results) {
        $results[$recur['id']] = $this->generatePendingContributionForRecur($recur);
      });
    }
    return $results;
  }

  /**
   * Generate Pending contributions for a specific ContributionRecur.
   *
   * This is called from within a transaction so we expect that to be
   * automatically rolled back if we throw an exception.
   *
   * @return int The new contribution ID.
   */
  public function generatePendingContributionForRecur(array $cr): int {
    $cnDate = $cr['next_sched_contribution_date'];

    // Set the next collection date
    $event = GenericHookEvent::create(
      [
        'originalDate'          => $cnDate,
        'newDate'               => date('Y-m-d H:i:s', strtotime("$cnDate + $cr[frequency_interval] $cr[frequency_unit]")),
        'frequency_interval'    => $cr['frequency_interval'],
        'frequency_unit'        => $cr['frequency_unit'],
        'cycle_day'             => $cr['cycle_day'],
        'contribution_recur_id' => $cr['id'],
      ]);
    \Civi::dispatcher()->dispatch('civi.recur.nextschedcontributiondatealter', $event);
    $newNextCNDate = $event->newDate;
    ContributionRecur::update(FALSE)
      ->addValue('next_sched_contribution_date', $newNextCNDate)
      ->addWhere('id', '=', $cr['id'])
      ->execute();

    // Pre-checks to ensure that repeattransaction will work.
    $cn = CRM_Contribute_BAO_ContributionRecur::getTemplateContribution($cr['id']);
    if (empty($cn)) {
      // @todo implement some handling.
      throw new CRM_Core_Exception("getTemplateContribution failed for ContributionRecur $cr[id]");
    }
    $cnStatus = CRM_Contribute_BAO_Contribution::buildOptions('contribution_status_id', 'validate')[$cn['contribution_status_id']];
    switch ($cnStatus) {
    case "Template":
    case "Completed":
      break;
    case "Pending":
      \Civi::log()->warning("[adyen]: ContributionRecur $cr[id] is due for another Contribution ($cnDate), but there is already a pending one (id:$cn[id], $cn[receive_date]). We will not create another pending contribution until that one is Completed.");
      return 0;
    default:
      // @todo implement some handling.
      throw new CRM_Core_Exception("Failed to find a suitable contribution to copy to create next scheduled contribution on ContributionRecur $cr[id]. getTemplateContribution returned one in status '$cnStatus'");
    }

    // @see docs/discussion/index.md
    $repeattransactionParams = [
      'original_contribution_id' => $cn['id'],
      'is_email_receipt'         => FALSE, /* We don't want this happening, we’re creating a pending one. */
      'receive_date'             => $cnDate,
      'is_test'                  => $cr['is_test'],
    ];
    // The total_amount: normally the same as the last contribution, unless
    // they have upgraded, in which case it should be the same as the Recur's
    // amount, which, if it exists should be the same as the Template
    // contribution's amount. Phew.
    if ($cnStatus === 'Completed' && $cr['amount'] !== $cn['total_amount']) {
      // Handle the case where the CR has been updated yet we're relying on the last Completed Contribution
      // for repeattransactionParams. Note that this is not ideal because the line items will no longer add
      // up to the total, leading to the qty field being adjusted to force a fit. To allow for upgrades, we
      // should begin using template contribututions; i.e. this code is hopefully redundant.
      $repeattransactionParams['total_amount'] = $cr['amount'];
      \Civi::log()->warning("[adyen]: ContributionRecur $cr[id] has a different amount ($cr[currency] $cr[amount]) than the last Completed Contribution ($cn[currency] $cr[amount], ID: $cn[id]). This can cause problems.");
    }
    $cnPending = civicrm_api3('Contribution', 'repeattransaction', $repeattransactionParams);

    // We can not supply an invoice_id to repeattransaction, and anyway we want to use the Contribution ID
    // which hadn't existed at that point. This invoice_id will be passed to Adyen as a merchantReference.
    Contribution::update(FALSE)
    ->addWhere('id', '=', $cnPending['id'])
    ->addValue('invoice_id', "CiviCRM-cn{$cnPending['id']}-cr{$cr['id']}")
    ->execute();

    return (int) $cnPending['id'];
  }

  /**
   * Process Pending contributions using Adyen API.
   */
  public function processPendingContributions(): array {
    $contributions = Contribution::get()
    ->addSelect('*', 'cr.payment_processor_id')
    ->addWhere('is_test', 'IN', [0, 1])
    // The commented version did not work
    // ->addJoin('ContributionRecur AS cr', 'INNER', NULL,
    //   ['cr.id', '=', 'contribution_recur_id'],
    //   ['cr.contribution_status_id:name', 'IN', ['In Progress', 'Overdue', 'Failing']],
    //   ['cr.payment_processor_id.is_active', '=', 1],
    //   ['cr.payment_processor_id.payment_processor_type_id:name', '=', '"Adyen"'],
    // )
    ->addJoin('ContributionRecur AS cr', 'INNER',
      ['contribution_recur_id', '=', 'cr.id'],
      ['cr.contribution_status_id:name', 'IN', ['In Progress', 'Overdue', 'Failing']],
    )
    ->addJoin('PaymentProcessor AS pp', 'INNER', ['cr.payment_processor_id', '=', 'pp.id'], ['pp.is_active', '=', 1])
    ->addJoin('PaymentProcessorType AS ppt', 'INNER', ['pp.payment_processor_type_id', '=', 'ppt.id'], ['ppt.name', '=', '"Adyen"'])
    ->addJoin('Contact AS ct', 'INNER', NULL,
      ['contact_id', '=', 'ct.id'],
      ['ct.is_deleted', '=', FALSE],
      ['ct.is_deceased', '=', FALSE],
    )
    ->addWhere('contribution_status_id:name', '=', 'Pending')
    ->execute();

    $results = [];
    foreach ($contributions as $contribution) {
      $results[$contribution['id']] = $this->processPendingContribution($contribution);
    }
    return $results;
  }

  /**
   * Process a single contribution using Adyen API.
   *
   * @param array $contribution (must include cr.payment_processor_id)
   */
  public function processPendingContribution(array $contribution): bool {
    $paymentProcessorID = $contribution['cr.payment_processor_id'];

    /** @var CRM_Core_Payment_Adyen $paymentProcessor */
    $paymentProcessor = \Civi\Payment\System::singleton()->getById($paymentProcessorID);
    if (!($paymentProcessor instanceof CRM_Core_Payment_Adyen)) {
      throw new \RuntimeException("processPendingContribution called with a contribution that is not attached to an Adyen payment processor. This is a bug!");
    }

    $result = $paymentProcessor->attemptPayment($contribution);
    $returnValue = NULL;
    if ($result['success'] ?? FALSE) {
      // Payment successfully processed; either it is settled or at least authorised. IPNs are relied upon to update further.
      // For our purposes, such a result means a successful, 'Completed' Contribution.

      // Q. how to record it complete?
      // A. add a Payment, with the PSP.
      $paymentCreateParams = [
        'contribution_id'                   => $contribution['id'],
        'total_amount'                      => $contribution['total_amount'],
        'trxn_id'                           => $result['pspReference'],
        'trxn_date'                         => date('Y-m-d H:i:s'),
        'is_send_contribution_notification' => FALSE, /* @todo? */
        // trxn_result_code ?
        // order_reference ?
      ];
      $result = civicrm_api3('Payment', 'create', $paymentCreateParams);
      $returnValue = TRUE;
      $crUpdates = [
        'failure_count'               => 0,
        'contribution_status_id:name' => 'In Progress',
        'failure_retry_date'          => NULL,
      ];
    }
    else {
      // Some failure.
      $cr = ContributionRecur::get(FALSE)
        ->addSelect('failure_count', 'contribution_status_id:name')
        ->addWhere('id', '=', $contribution['contribution_recur_id'])
        ->execute()->single();
      $previousFailureCount = (int) ($cr['failure_count'] ?? 0);

      \Civi::log()->warning("[adyen] Failed attempting to take contribution $contribution[id] of $contribution[currency] $contribution[total_amount],  got result:\n" . json_encode($result, JSON_PRETTY_PRINT));

      // Mark this Contribution as failed.
      Contribution::update(FALSE)
        ->addWhere('id', '=', $contribution['id'])
        ->addValue('contribution_status_id:name', 'Failed')
        ->execute();

      $crUpdates = [
        'failure_count'               => $previousFailureCount+1,
        'contribution_status_id:name' => 'Failing',
      ];

      $retryPolicy = $paymentProcessor->getRetryPolicy();
      $activePolicy = $retryPolicy[$previousFailureCount] ?? 'skip';
      if (substr($activePolicy, 0, 1) === '+') {
        $crUpdates['failure_retry_date'] = date('Y-m-d', strtotime("today $activePolicy"));
        \Civi::log()->notice("[adyen] Scheduling retry for failed contribution $contribution[id] for $crUpdates[failure_retry_date]:");
      }
      elseif ($activePolicy === 'skip') {
        \Civi::log()->notice("[adyen] Not scheduling a retry for failed contribution $contribution[id], we will try again next cycle.");
        $crUpdates['failure_retry_date'] = NULL;
      }
      elseif ($activePolicy === 'fail') {
        \Civi::log()->warning("[adyen] Marking recurring contribution Failed; no further retries or future payment attempts will be made.");
        $crUpdates['contribution_status_id:name'] = 'Failed';
        // Strictly, this is supposed to hold the date the contributor cancelled, but the only other date is end_date which is for successful completion.
        $crUpdates['cancel_date'] = date('Y-m-d H:i:s');
      }

      $returnValue = FALSE;
    }

    ContributionRecur::update(FALSE)
    ->addWhere('id', '=', $contribution['contribution_recur_id'])
    ->setValues($crUpdates)
    ->execute();

    return $returnValue;
  }
}
