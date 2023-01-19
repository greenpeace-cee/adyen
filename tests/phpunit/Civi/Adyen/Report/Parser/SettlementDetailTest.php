<?php

use Civi\Api4\AdyenNotification;
use Civi\Adyen\Report\Parser\SettlementDetail;
use Civi\Api4\AdyenReportLine;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test parsing of SettlementDetail reports
 *
 * @group headless
 */
class Civi_Adyen_Report_Parser_SettlementDetailTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  const FIXTURE_PATH = __DIR__ . '/../../../../../fixtures';

  private $report;

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
    $this->report = AdyenNotification::create()
      ->addValue('event_code_id', 1)
      ->addValue('event_date', '2020-01-23')
      ->addValue('psp_reference', 'settlement_detail_report_batch_89.csv')
      ->addValue('merchant_account', 'SampleMerchantAccount')
      ->addValue('notification', [])
      ->execute()
      ->first();
  }

  public function tearDown() {
    parent::tearDown();
  }

  public function testParser() {
    $csv = file_get_contents(self::FIXTURE_PATH . '/settlement_detail_report_batch_89.csv');
    $settlementDetailParser = new SettlementDetail($csv);
    $settlementDetailParser->setNotificationId($this->report['id']);
    $this->assertEquals(5, $settlementDetailParser->count());
    $settlementDetailParser->store();

    $adyenReportLines = AdyenReportLine::get()
      ->addWhere('adyen_notification_id', '=', $this->report['id'])
      ->addWhere('status_id', '=', 1)
      ->addOrderBy('line_date', 'ASC')
      ->addOrderBy('line_number', 'ASC')
      ->execute();

    $this->assertEquals(5, $adyenReportLines->count());
    $this->assertEquals('2020-01-21 07:11:17', $adyenReportLines->itemAt(1)['line_date']);
    $this->assertEquals(3, $adyenReportLines->itemAt(1)['line_number']);
    $this->assertEquals('2020-01-21 08:11:17', $adyenReportLines->itemAt(2)['line_date']);
    $this->assertEquals(2, $adyenReportLines->itemAt(2)['line_number']);
  }

}
