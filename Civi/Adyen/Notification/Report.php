<?php

namespace Civi\Adyen\Notification;

use Civi\Adyen\Configuration;
use Civi\Adyen\Report\Parser;
use Civi\Adyen\Report\Processor;
use Civi\Api4\AdyenNotification;
use Civi\Api4\AdyenReportLine;
use GuzzleHttp\Client;

/**
 * Handler for "REPORT_AVAILABLE" notifications
 *
 * @todo extract much of this into a base class for notifications
 *
 * @package Civi\Adyen\Notification
 */
class Report {

  public static $userAgent = NULL;

  private $id;
  private $merchantAccount;
  private $reportName;
  private $reportUrl;
  private $processingOrder = NULL;

  /**
   * @var \DateTime
   */
  private $date;

  /**
   * @var array
   */
  private $notification;

  public function __construct(array $notification) {
    $this->notification = $notification;
    $this->merchantAccount = $notification['merchantAccountCode'];
    $this->date = new \DateTime($notification['eventDate']);
    $this->reportName = $notification['pspReference'];
    $this->reportUrl = $notification['reason'];
    if (empty($notification['additionalData']['hmacSignature'])) {
      throw new \Exception('Invalid notification: no HMAC signature provided');
    }

    if (preg_match('/^settlement_detail_report_batch_(\d+)\.csv$/', $this->reportName, $batchNumber)) {
      $this->processingOrder = $batchNumber[1];
    }

    // verify HMAC
    $hmacValid = FALSE;
    $sig = new \Adyen\Util\HmacSignature();
    // iterate through all enabled HMAC keys and find one that verifies
    // we need to support multiple HMAC keys to support rotation
    foreach (Configuration::getMerchantConfiguration($this->merchantAccount, 'hmacKeys') as $hmacKey) {
      $hmacValid = $sig->isValidNotificationHMAC($hmacKey, $this->notification);
      if ($hmacValid) {
        // we found a valid signature, done
        break;
      }
    }

    // TODO: maybe add `&& !CRM_Utils_System::isDevelopment()`? YAGNI for now ...
    if (!$hmacValid) {
      throw new \Exception('Invalid notification: HMAC verification failed');
    }
  }

  public function setId($id) {
    $this->id = $id;
  }

  public function store() {
    $eventCode = \CRM_Core_PseudoConstant::getKey(
      'CRM_Adyen_BAO_AdyenNotification',
      'event_code_id',
      'report_available'
    );
    AdyenNotification::create()
      ->addValue('event_code_id', $eventCode)
      ->addValue('event_date', $this->date->format('Y-m-d H:i:s'))
      ->addValue('psp_reference', $this->notification['pspReference'])
      ->addValue('merchant_account', $this->merchantAccount)
      ->addValue('notification', $this->notification)
      ->addValue('processing_order', $this->processingOrder)
      ->setCheckPermissions(FALSE)
      ->execute();
  }

  public function process() {
    // we only care about settlement detail reports for now
    if (!preg_match('/^settlement_detail_report_batch_(\d+)\.csv$/', $this->reportName)) {
      \Civi::log()->info("[Adyen] Ignoring report {$this->reportName} (ID: {$this->id})");
      $this->updateStatus('ignored');
      return;
    }

    if ($this->isDuplicate()) {
      \Civi::log()->warning(
        "[Adyen] Report {$this->reportName} (ID: {$this->id}) appears to be a duplicate, ignoring"
      );
      $this->updateStatus('ignored');
      return;
    }

    $report = $this->fetchReport();
    \Civi::log()->info("[Adyen] Downloaded report {$this->reportName} (ID: {$this->id})");
    \CRM_Core_Transaction::create()->run(function() use ($report) {
      $this->updateStatus('in_progress');
      $this->storeReport($report);
      \Civi::log()->info("[Adyen] Stored report {$this->reportName} (ID: {$this->id})");
      if ($this->processReport()) {
        \Civi::log()->info("[Adyen] Processed report {$this->reportName} (ID: {$this->id})");
        $this->updateStatus('completed');
      }
      else {
        \Civi::log()->info("[Adyen] Partially processed report {$this->reportName} (ID: {$this->id})");
        // if we haven't processed all lines, go back to "pending"
        $this->updateStatus('pending');
      }
    });
  }

  /**
   * Check if this report is a duplicate
   *
   * @return bool
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function isDuplicate() {
    $eventCode = \CRM_Core_PseudoConstant::getKey(
      'CRM_Adyen_BAO_AdyenNotification',
      'event_code_id',
      'report_available'
    );
    $firstReceivedNotification = AdyenNotification::get()
      ->setSelect([
        'id',
      ])
      ->addWhere('event_code_id', '=', $eventCode)
      ->addWhere('psp_reference', '=', $this->reportName)
      ->addWhere('merchant_account', '=', $this->merchantAccount)
      ->addOrderBy('event_date', 'ASC')
      ->addOrderBy('id', 'ASC')
      ->execute()
      ->first();

    return $firstReceivedNotification['id'] != $this->id;
  }

  protected function updateStatus(string $status) {
    if (empty($this->id)) {
      throw new \Exception('Cannot update AdyenNotification without ID');
    }
    AdyenNotification::update()
      ->addWhere('id', '=', $this->id)
      ->addValue('status_id', \CRM_Core_PseudoConstant::getKey(
        'CRM_Adyen_BAO_AdyenNotification',
        'status_id',
        $status
      ))
      ->execute();
  }

  private function fetchReport() {
    // we accept any kind of HTTPS URL, which technically makes this an SSRF
    // Adyen doesn't really make any guarantees re: the URL, so there's not
    // a whole lot we can do at this level other than hope our PSP isn't trying
    // to pwn us
    if (parse_url($this->reportUrl, PHP_URL_SCHEME) != 'https') {
      throw new \Exception('Report URL must be HTTPS');
    }

    $client = new Client([
      'timeout'         => 60,
      'request.options' => [
        'headers' => [
          'Accept'     => 'text/csv',
          'User-Agent' => self::getUserAgent(),
        ],
      ],
    ]);
    $res = $client->request('GET', $this->reportUrl, [
      'auth' => [
        Configuration::getMerchantConfiguration($this->merchantAccount, 'reportUserName'),
        Configuration::getMerchantConfiguration($this->merchantAccount, 'reportUserPassword'),
      ],
    ]);
    if ($res->getStatusCode() != 200) {
      throw new \Exception('Unable to fetch Adyen report: HTTP ' . $res->getStatusCode());
    }
    return $res->getBody();
  }

  private function storeReport(string $report) {
    $settlementDetailReport = new Parser\SettlementDetail($report);
    // get number of already-stored report lines (should be 0)
    $adyenReportLineCount = AdyenReportLine::get()
      ->selectRowCount()
      ->addWhere('adyen_notification_id', '=', $this->id)
      ->execute()
      ->count();
    if ($adyenReportLineCount > 0) {
      if ($adyenReportLineCount == $settlementDetailReport->count()) {
        // looks like this report has already been stored
        \Civi::log()->warning("[Adyen] Report {$this->id} already stored, skipping");
        return;
      }
      else {
        throw new \Exception("[Adyen] Inconsistent report lines found for report {$this->id} (found {$adyenReportLineCount} in database, expected 0 or {$settlementDetailReport->count()})");
      }
    }
    $settlementDetailReport->setNotificationId($this->id);
    $settlementDetailReport->store();
  }

  private function processReport() {
    $success = TRUE;
    $notification = AdyenNotification::get()
      ->addWhere('id', '=', $this->id)
      ->execute()
      ->first();

    $reportLines = AdyenReportLine::get()
      ->addWhere('adyen_notification_id', '=', $this->id)
      ->addWhere('status_id', '=', 1)
      ->addOrderBy('line_date', 'ASC')
      ->addOrderBy('line_number', 'ASC')
      ->execute();

    foreach ($reportLines as $reportLine) {
      $reportProcessor = new Processor\SettlementDetail($reportLine, $notification);
      if (!$reportProcessor->process()) {
        $success = FALSE;
      }
    }
    return $success;
  }

  /**
   * Get the user agent for HTTP requests
   *
   * @return string|null
   * @throws \CiviCRM_API3_Exception
   */
  public static function getUserAgent() {
    if (is_null(self::$userAgent)) {
      $version = civicrm_api3('Extension', 'getvalue', [
        'return' => 'version',
        'key' => \CRM_Adyen_ExtensionUtil::LONG_NAME,
      ]);
      self::$userAgent = 'CiviCRM/' . \CRM_Utils_System::version() . ' (' . \CRM_Adyen_ExtensionUtil::LONG_NAME . '/' . $version . ')';
    }
    return self::$userAgent;
  }

}
