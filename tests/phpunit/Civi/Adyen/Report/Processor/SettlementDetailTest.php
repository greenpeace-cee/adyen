<?php

use Civi\Api4\AdyenNotification;
use Civi\Api4\AdyenReportLine;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test processing of SettlementDetail reports
 *
 * @group headless
 */
class Civi_Adyen_Report_Processor_SettlementDetailTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use \Civi\Test\Api3TestTrait;

  private $contact;
  private $report;

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply(TRUE);
  }

  public function setUp() {
    parent::setUp();
    $this->callAPISuccess('CustomGroup', 'create', [
      'title'                       => 'Contribution Information',
      'name'                        => 'contribution_information',
      'extends'                     => 'Contribution',
    ]);

    $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => 'contribution_information',
      'label'           => 'Cancellation Costs',
      'name'            => 'cancellation_costs',
      'data_type'       => 'Money',
      'html_type'       => 'Text',
    ]);
    $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => 'contribution_information',
      'label'           => 'Refund Amount',
      'name'            => 'refund_amount',
      'data_type'       => 'Money',
      'html_type'       => 'Text',
    ]);
    $this->report = AdyenNotification::create()
      ->addValue('event_code_id', 1)
      ->addValue('event_date', '2020-02-24')
      ->addValue('psp_reference', 'settlement_detail_report_batch_1.csv')
      ->addValue('merchant_account', 'SampleMerchantAccount')
      ->addValue('notification', [])
      ->execute()
      ->first();
    $this->contact = Contact::create()
      ->addValue('first_name', 'John')
      ->addValue('last_name', 'Doe')
      ->execute()
      ->first();
  }

  public function tearDown() {
    parent::tearDown();
  }

  private function createReportLine(int $lineNumber, array $content) {
    $template = [
      'Company Account' => 'SampleAccount',
      'Merchant Account' => 'SampleMerchantAccount',
      'Psp Reference' => '881579590624038B',
      'Merchant Reference' => 'TEST-REF-123',
      'Payment Method' => 'eps',
      'Creation Date' => '2020-02-04 13:30:01',
      'TimeZone' => 'UTC',
      'Type' => 'Settled',
      'Modification Reference' => '881579590624038B',
      'Gross Currency' => 'EUR',
      'Gross Debit (GC)' => '',
      'Gross Credit (GC)' => '50.00',
      'Exchange Rate' => '1',
      'Net Currency' => 'EUR',
      'Net Debit (NC)' => '',
      'Net Credit (NC)' => '49.00',
      'Commission (NC)' => '1.00',
      'Markup (NC)' => '',
      'Scheme Fees (NC)' => '',
      'Interchange (NC)' => '',
      'Payment Method Variant' => 'eps',
      'Modification Merchant Reference' => 'TEST-REF-123',
      'Batch Number' => '1',
      'Reserved4' => '',
      'Reserved5' => '',
      'Reserved6' => '',
      'Reserved7' => '',
      'Reserved8' => '',
      'Reserved9' => '',
      'Reserved10' => '',
    ];

    $reportLine = AdyenReportLine::create()
      ->addValue('adyen_notification_id', $this->report['id'])
      ->addValue('line_number', $lineNumber)
      ->addValue('content', array_merge($template, $content))
      ->execute()
      ->first();
    // refresh so we get an array-representation of JSON in content
    return AdyenReportLine::get()
      ->addWhere('id', '=', $reportLine['id'])
      ->execute()
      ->first();

  }

  /**
   * Test report processing
   *
   * @todo: REF to use datasource
   *
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testSettlement() {
    // Test 1: Status=Settled
    $contribution = Contribution::create()
      ->addValue('contact_id', $this->contact['id'])
      ->addValue('financial_type_id', 1)
      ->addValue('total_amount', 45)
      ->addValue('contribution_status_id', 5)
      ->addValue('trxn_id', 'TEST-REF-123')
      ->execute()
      ->first();
    $reportProcessor = new Civi\Adyen\Report\Processor\SettlementDetail(
      $this->createReportLine(1, []),
      $this->report
    );
    $this->assertTrue($reportProcessor->process());
    $this->assertContributionDetailsMatch($contribution['id'], [
      'contribution_status_id' => 1,
      'total_amount' => 50.00,
      'net_amount' => 49.00,
      'fee_amount' => 1.00,
    ]);

    // Test 2: Chargeback - Status=Chargeback
    $reportProcessor = new Civi\Adyen\Report\Processor\SettlementDetail(
      $this->createReportLine(2, [
        'Type' => 'Chargeback',
        'Gross Debit (GC)' => '50.00',
        'Gross Credit (GC)' => '',
        'Net Debit (NC)' => '57.50',
        'Commission (NC)' => '7.50',
      ]),
      $this->report
    );
    $this->assertTrue($reportProcessor->process());
    $this->assertContributionDetailsMatch($contribution['id'], [
      'contribution_status_id' => 3,
      'cancel_date' => '2020-02-24 00:00:00',
      'total_amount' => 50.00,
      'net_amount' => 41.50,
      'fee_amount' => 8.50,
      'contribution_information.cancellation_costs' => 7.50,
      'contribution_information.refund_amount' => 50.00,
    ]);
    // Test 3: Status=ChargebackReversed
    $reportProcessor = new Civi\Adyen\Report\Processor\SettlementDetail(
      $this->createReportLine(3, [
        'Type' => 'ChargebackReversed',
      ]),
      $this->report
    );
    $this->assertTrue($reportProcessor->process());
    $this->assertContributionDetailsMatch($contribution['id'], [
      'contribution_status_id' => 1,
      'total_amount' => 50.00,
      'net_amount' => 40.50,
      'fee_amount' => 9.50,
    ]);
  }

  private function assertContributionDetailsMatch(int $contributionId, array $expected) {
    $contribution = Contribution::get()
      ->setSelect(array_keys($expected))
      ->addWhere('id', '=', $contributionId)
      ->execute()
      ->first();
    foreach ($expected as $key => $value) {
      $this->assertEquals($value, $contribution[$key], "Contribution.{$key} should be {$value}");
    }
  }

}
