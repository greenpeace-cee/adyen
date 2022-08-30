<?php
namespace Civi\Api4\Action\ContributionRecur;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\Result;
use CRM_Core_Exception;
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
    $lock = new \CRM_Core_Lock('worker.contribute.processadyen', 90, TRUE);
    $lock->acquire();
    if (!$lock->isAcquired()) {
      throw new CRM_Core_Exception("Failed to get a lock to process Adyen contributions. Try later.");
    }

    try {
      // todo can we do this or do we need to return an array of arrays?
      $result['newPending'] = $this->generatePendingContributions();
      $this->processPendingContributions();
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
   * @return array of created contribution IDs, keyed by ContributionRecur ID.
   */
  public function generatePendingContributions(): array {
    $dueRecurs = ContributionRecur::get(FALSE)
      ->addWhere('payment_processor_id.payment_processor_type_id:name', '=', 'Adyen')
      ->addWhere('payment_processor_id.is_active', '=', true)
      ->addWhere('contribution_status_id:name', 'IN', ['In Progress', 'Overdue', 'Failing'])
      ->addWhere('next_sched_contribution_date', '<=', 'today')
      ->addWhere('is_test', 'IN', [0, 1])
      ->execute();
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
   * @return int The new contribution ID.
   */
  public function generatePendingContributionForRecur(array $cr): int {
    $cnDate = $cr['next_sched_contribution_date'];

    // Set the next collection date
    $newNextCNDate = date('Y-m-d H:i:s', strtotime("$cnDate + $cr[frequency_interval] $cr[frequency_unit]"));
    ContributionRecur::update(FALSE)
      ->addValue('next_sched_contribution_date', $newNextCNDate)
      ->addWhere('id', '=', $cr['id'])
      ->execute();

    // Pre-checks to ensure that repeattransaction will work.
    $cn = CRM_Contribute_BAO_ContributionRecur::getTemplateContribution($cr['id']);
    if (empty($cn)) {
      // @todo implement some handling.
      throw new CRM_Core_Exception("getTemplateContribution failed fro ContributionRecur $cr[id]");
    }
    $cnStatus = CRM_Contribute_BAO_Contribution::buildOptions('contribution_status_id', 'validate')[$cn['contribution_status_id']];
    if (!in_array($cnStatus, ['Template', 'Completed'])) {
      // @todo implement some handling.
      throw new CRM_Core_Exception("Failed to find a suitable contribution to copy to create next scheduled contribution on ContributionRecur $cr[id]");
    }

    // @see docs/discussion/index.md
    $repeattransactionParams = [
      'original_contribution_id' => $cn['id'],
      'is_email_receipt'         => FALSE, /* We don't want this happening, we’re creating a pending one. */
      'trxn_id'                  => $this->determineTrxnID($cr, $cn, $cnStatus),
      'receive_date'             => $cnDate,
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

    return (int) $cnPending['id'];
  }

  /**
   *
   */
  public function determineTrxnID(array $cr, array $cn, string $cnStatus): string {
    // We don't have a trxn_id There should be only one for this CR and this date.
    // This will become the 'merchant identifier' at Adyen.
    $trxn_id = "CiviCRM-cr$cr[id]-" . date('Y-m-d');
    // Check this trxn_id is new.
    $existing = \Civi\Api4\Contribution::get(FALSE)
      ->addWhere('trxn_id', 'LIKE', "$trxn_id%")
      ->addSelect('trxn_id')
      ->execute()->last();
    if ($existing) {
      // This means we generated one or more Contributions that failed.
      if (preg_match('/CiviCRM-cr\d+-\d{4}-\d\d-\d\d-(\d)$/', $existing['trxn_id'] ?? '', $matches)) {
        // This is going to be the 3rd or later attempt.
        $trxn_id .= "-" . (((int) $matches[1]) + 1);
      }
      else {
        // This is the 2nd
        $trxn_id .= "-1";
      }
    }
    return $trxn_id;
  }

  /**
   * Process Pending contributions using Adyen API.
   */
  public function processPendingContributions() {

  }
}
