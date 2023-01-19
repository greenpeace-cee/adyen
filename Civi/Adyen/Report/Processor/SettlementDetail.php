<?php

namespace Civi\Adyen\Report\Processor;

use Civi\Api4\AdyenReportLine;
use Civi\Api4\Contribution;

/**
 * Process Settlement Detail Reports
 *
 * Partially based on Wikimedia's SmashPig project (PaymentProviders/Adyen/Audit/AdyenAudit.php)
 *
 * @package Civi\Adyen\Report\Parser
 */
class SettlementDetail {

  private $ignoredTypes = [
    'fee',
    'misccosts',
    'merchantpayout',
    'depositcorrection',
    'invoicededuction',
    'matchedstatement',
    'manualcorrected',
    'authorisationschemefee',
    'bankinstructionreturned',
    'internalcompanypayout',
    'epapaid',
    'balancetransfer',
    'paymentcost',
    'settlecost',
    'paidout',
    'paidoutreversed',
    'reserveadjustment',
  ];

  /**
   * Adyen Notification
   *
   * @var array
   */

  private $notification;

  /**
   * AdyenReportLine
   *
   * @var array
   */
  private $reportLine;

  public function __construct(array $reportLine, array $notification) {
    $this->notification = $notification;
    $this->reportLine = $reportLine;
  }

  public function process() {
    $this->updateStatus('in_progress');
    if (in_array(strtolower($this->reportLine['content']['Type']), $this->ignoredTypes)) {
      \Civi::log()->debug("[Adyen] Ignoring report line {$this->reportLine['line_number']}");
      $this->updateStatus('completed');
      return TRUE;
    }
    else {
      if ($this->processContribution()) {
        $this->updateStatus('completed');
        return TRUE;
      }
      else {
        $this->updateStatus('pending');
        return FALSE;
      }
    }
  }

  protected function processContribution() {
    $trxn_id = $this->reportLine['content']['Merchant Reference'];
    if (empty($trxn_id)) {
      \Civi::log()->warning("[Adyen] Empty trxn_id in report line {$this->reportLine['line_number']}");
      return FALSE;
    }
    // TODO: GP specific: contribution_information.*
    $contribution = Contribution::get()
      ->setSelect([
        'id',
        'receive_date',
        'total_amount',
        'fee_amount',
        'cancel_date',
        'cancel_reason',
        'contribution_status_id',
        'contribution_information.cancellation_costs',
        'contribution_information.refund_amount',
      ])
      ->addWhere('trxn_id', '=', $trxn_id)
      ->execute()
      ->first();
    if (empty($contribution)) {
      \Civi::log()->warning("[Adyen] Unknown contribution with trxn_id {$trxn_id} in report line {$this->reportLine['line_number']}");
      return FALSE;
    }

    $updateParams = [
      'id'           => $contribution['id'],
      'fee_amount'   => floatval($contribution['fee_amount']) + $this->calculateFee(),
    ];
    switch (strtolower($this->reportLine['content']['Type'])) {
      case 'settled':
      case 'chargebackreversed':
      case 'refundedreversed':
        $updateParams['total_amount'] = floatval(
          $this->reportLine['content']['Gross Credit (GC)']
        );
        $updateParams['contribution_status_id'] = \CRM_Core_PseudoConstant::getKey(
          'CRM_Contribute_BAO_Contribution',
          'contribution_status_id',
          'Completed'
        );
        $updateParams['cancel_date'] = NULL;
        $updateParams['cancel_reason'] = NULL;
        break;

      case 'chargeback':
        // TODO: GP specific
        $updateParams['contribution_status_id'] = \CRM_Core_PseudoConstant::getKey(
          'CRM_Contribute_BAO_Contribution',
          'contribution_status_id',
          'Cancelled'
        );
        // TODO: GP specific
        $updateParams['cancel_reason'] = 'CC96';
        // No break
      case 'refunded':
        if (empty($updateParams['contribution_status_id'])) {
          $updateParams['contribution_status_id'] = \CRM_Core_PseudoConstant::getKey(
            'CRM_Contribute_BAO_Contribution',
            'contribution_status_id',
            'Refunded'
          );
        }

        $updateParams['cancel_date'] = $this->notification['event_date'];

        if (empty($this->reportLine['content']['Gross Debit (GC)'])) {
          \Civi::log()->warning(
            "[Adyen] Found Chargeback or Refund without Gross Debit (GC) for trxn_id {$trxn_id}"
          );
        }
        else {
          // TODO: GP specific
          $updateParams['contribution_information.refund_amount'] = floatval(
            $this->reportLine['content']['Gross Debit (GC)']
          );
        }
        if (!empty($this->reportLine['content']['Commission (NC)'])) {
          // this fee is specifically for the chargeback/refund
          // TODO: GP specific
          $updateParams['contribution_information.cancellation_costs'] = floatval(
            $this->reportLine['content']['Commission (NC)']
          );
        }
        break;
    }

    if (array_key_exists('total_amount', $updateParams)
      && $updateParams['total_amount'] != $contribution['total_amount']) {
      \Civi::log()->warning(
        "[Adyen] Updating total_amount for trxn_id {$trxn_id}"
      );
    }

    if (array_key_exists('contribution_status_id', $updateParams)
      && $updateParams['contribution_status_id'] != $contribution['contribution_status_id']) {
      \Civi::log()->info(
        "[Adyen] Changing contribution_status_id from {$contribution['contribution_status_id']} to {$updateParams['contribution_status_id']} for trxn_id {$trxn_id}"
      );
    }

    // TODO: GP specific
    if (array_key_exists('contribution_information.refund_amount', $updateParams)
      && $updateParams['contribution_information.refund_amount'] != $contribution['total_amount']) {
      \Civi::log()->warning(
        "[Adyen] Found Chargeback/Refund with refund amount differing from total_amount for trxn_id {$trxn_id}"
      );
    }

    \Civi::log()->debug("[Adyen] Updating contribution with trxn_id {$trxn_id} from line {$this->reportLine['line_number']}");

    Contribution::update()
      ->addWhere('id', '=', $contribution['id'])
      ->setValues($updateParams)
      ->execute();
    return TRUE;
  }

  protected function updateStatus(string $status) {
    if (empty($this->reportLine['id'])) {
      throw new \Exception('Cannot update AdyenReportLine without ID');
    }
    AdyenReportLine::update()
      ->addWhere('id', '=', $this->reportLine['id'])
      ->addValue('status_id', \CRM_Core_PseudoConstant::getKey(
        'CRM_Adyen_BAO_AdyenReportLine',
        'status_id',
        $status
      ))
      ->execute();
  }

  protected function calculateFee() {
    // fee is given in settlement currency, but we store original
    $exchange = $this->reportLine['content']['Exchange Rate'];
    $fee = floatval($this->reportLine['content']['Commission (NC)']) +
      floatval($this->reportLine['content']['Markup (NC)']) +
      floatval($this->reportLine['content']['Scheme Fees (NC)']) +
      floatval($this->reportLine['content']['Interchange (NC)']);
    return round($fee / $exchange, 2);
  }

  protected function isChargeback() {
    return strtolower($this->reportLine['content']['Type']) == 'chargeback';
  }

}
