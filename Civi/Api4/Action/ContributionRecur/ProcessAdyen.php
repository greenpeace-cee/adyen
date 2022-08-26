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
      $this->generatePendingContributions();
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
   */
  public function generatePendingContributions() {
    $dueRecurs = ContributionRecur::get(FALSE)
      ->addWhere('payment_processor_id.payment_processor_type_id:name', '=', 'Adyen')
      ->addWhere('payment_processor_id.is_active', '=', true)
      ->addWhere('contribution_status_id:name', 'IN', ['In Progress', 'Overdue', 'Failing'])
      ->addWhere('next_sched_contribution_date', '>=', 'now')
      ->addWhere('is_test', 'IN', [0, 1])
      ->execute();
    foreach ($dueRecurs as $recur) {
      // Use a transaction to ensure that the next_sched_contribution_date is only updated
      // on successful creation of a Contribution. That way we don’t miss a contribution if
      // there is a problem creating one.
      CRM_Core_Transaction::create()->run(function() use ($recur) {
        $this->generatePendingContributionForRecur($recur);
      });
    }
  }

  /**
   * Generate Pending contributions for a specific ContributionRecur.
   *
   */
  public function generatePendingContributionForRecur(array $cr) {
    $cnDate = $cr['next_sched_contribution_date'];

    // Set the next collection date
    $newNextCNDate = date('Y-m-d H:i:s', strtotime("$cnDate + $cr[frequency_interval] $cr[frequency_unit]"));
    ContributionRecur::update(FALSE)
      ->addValue('next_sched_contribution_date', $newNextCNDate)
      ->addWhere('id', '=', $cr['id'])
      ->execute();

    // Find a contribution to copy from.
    $cn = CRM_Contribute_BAO_ContributionRecur::getTemplateContribution($cr['id']);
    $cnStatus = CRM_Contribute_BAO_Contribution::buildOptions('contribution_status_id', 'validate')[$cn['contribution_status_id']];
    if (!in_array($cnStatus, ['Template', 'Compeleted'])) {
      // @todo implement some handling.
      throw new CRM_Core_Exception("Failed to find a suitable contribution to copy to create next scheduled contribution on ContributionRecur $cr[id]");
    }

    $newTrxnID = '?' . $cn['trxn_id'];
    $newContributionParams = [     // Prepare to update the contribution records.
      'total_amount'           => $cn['total_amount'],
      'receive_date'           => $cnDate,
      'contribution_recur_id'  => $cr['id'],
      'financial_type_id'      => $cr['financial_type_id'],
      'contact_id'             => $cr['contact_id'],
      'is_test'                => $cr['is_test'],
      'trxn_id'                => $newTrxnID,
    ];

    // @todo line items; other gubbins.
    // Order.create...


  }

  /**
   * Process Pending contributions using Adyen API.
   */
  public function processPendingContributions() {

  }
}
