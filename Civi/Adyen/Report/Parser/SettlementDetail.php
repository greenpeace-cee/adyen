<?php

namespace Civi\Adyen\Report\Parser;

use Civi\Api4\AdyenReportLine;

/**
 * Parse Settlement Detail Reports
 *
 * Partially based on Wikimedia's SmashPig project (PaymentProviders/Adyen/Audit/AdyenAudit.php)
 *
 * @package Civi\Adyen\Report\Parser
 */
class SettlementDetail {

  protected $columnHeaders = [
    'Company Account',
    'Merchant Account',
    'Psp Reference',
    'Merchant Reference',
    'Payment Method',
    'Creation Date',
    'TimeZone',
    'Type',
    'Modification Reference',
    'Gross Currency',
    'Gross Debit (GC)',
    'Gross Credit (GC)',
    'Exchange Rate',
    'Net Currency',
    'Net Debit (NC)',
    'Net Credit (NC)',
    'Commission (NC)',
    'Markup (NC)',
    'Scheme Fees (NC)',
    'Interchange (NC)',
    'Payment Method Variant',
    'Modification Merchant Reference',
    'Batch Number',
    'Reserved4',
    'Reserved5',
    'Reserved6',
    'Reserved7',
    'Reserved8',
    'Reserved9',
    'Reserved10',
  ];

  /**
   * CSV content in format [['Company Account' => 'foo', ...], ...]
   *
   * @var array
   */
  protected $data = [];
  protected $notificationId;

  public function __construct($data) {
    $lines = explode("\n", trim($data));
    // check that header matches expected format
    if ($this->lineToArray($lines[0]) != $this->columnHeaders) {
      throw new \Exception('Invalid column headers');
    }
    // remove header
    unset($lines[0]);
    foreach ($lines as $line) {
      $this->data[] = array_combine($this->columnHeaders, str_getcsv($line));
    }
  }

  private function lineToArray(string $line) {
    return str_getcsv($line, ',', '"', '\\');
  }

  public function setNotificationId($notificationId) {
    $this->notificationId = $notificationId;
  }

  public function count() {
    return count($this->data);
  }

  public function store() {
    foreach ($this->data as $lineNumber => $row) {
      AdyenReportLine::create()
        ->addValue('line_number', $lineNumber + 1)
        ->addValue('line_date', $row['Creation Date'])
        ->addValue('content', $row)
        ->addValue('adyen_notification_id', $this->notificationId)
        ->execute();
    }
  }

}
