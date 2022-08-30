<?php

// use CRM_Adyen_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Core\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\Test\CiviEnvBuilder;
use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Action\ContributionRecur\ProcessAdyen;

/**
 * Tests the ContributionRecur.processAdyen API4 action.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class WebhookEventHandlerTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {


  /** @var array holds the data for the test mode processor */
  public array $testModePaymentProcessorConfig;

  /**
   * Holds the primary test ContributionRecur ID
   */
  protected int $contactID;

  /**
   * Holds the primary test ContributionRecur ID
   */
  protected int $crID;

  /**
   * Holds the first test Contribution ID
   */
  protected int $cn1ID;

  /**
   * Setup used when HeadlessInterface is implemented.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * @link https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   *
   * @return \Civi\Test\CiviEnvBuilder
   *
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install('mjwshared')
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp():void {
    parent::setUp();

    $this->testModePaymentProcessorConfig = \Civi\Api4\PaymentProcessor::save(FALSE)
    ->setReload(TRUE)
    ->setDefaults([
      'payment_processor_type_id:name' => "Adyen",
      'name'                           => "Adyen",
      'class_name'                     => 'Payment_Adyen',
      'billing_mode'                   => 1, // ?
      'description'                    => "Set up by test script",
      'is_active'                      => 1,
      'is_recur'                       => 1,
      'payment_type'                   => 1,
      'url_api'                        => 'http://notused.example.org',
      'payment_instrument_id:name'     => "credit_card",
      'domain_id'                      => 1,
    ])
    ->setRecords([
      [
        'is_test'   => 1,
        'user_name' => "fake_test_api_user",
        'password'  => 'fake_password',
        'signature' => json_encode([
          'clientKey' => 'test_DUMMYCLIENTKEY',
          'urlPrefix' => '', // only used for live mode.
          'hmacKeys'  => [
            'SECRET_HMAC_KEY_1',
            'SECRET_HMAC_KEY_2',
          ]
        ], JSON_PRETTY_PRINT),
      ],
      [
        'is_test'   => 0,
        'user_name' => "fake_live_api_user",
        'password'  => 'fake_live_password',
        'signature' => json_encode([
          'clientKey' => 'live_DUMMYCLIENTKEY',
          'urlPrefix' => 'https://something.example.org', // only used for live mode.
          'hmacKeys'  => [
            'SECRET_HMAC_KEY_3',
            'SECRET_HMAC_KEY_4',
          ]
        ], JSON_PRETTY_PRINT),
      ],
    ])
    ->execute()->first();

    $this->testModePaymentProcessorObject = \Civi\Payment\System::singleton()->getByProcessor($this->testModePaymentProcessorConfig);

    // Create a contact, a ContributionRecur and a completed Contribution, simulating entities created by existing outside processes.
    $this->contactID = Contact::create(FALSE)
      ->setValues(['display_name' => 'Wilma'])->execute()->single()['id'];

    // The contribution we assume is Completed a month ago, and the
    // CR is now due today.
    $dateOfFirstContribution = date('Y-m-d H:i:s', strtotime('today - 1 month'));

    // @todo cycle_day
    $this->crID = ContributionRecur::create(FALSE)
      ->setValues([
        'contact_id'                   => $this->contactID,
        'amount'                       => '1.23',
        'currency'                     => 'EUR',
        'contribution_status_id:name'  => 'In Progress',
        'financial_type_id'            => 1,
        'frequency_unit'               => 'month',
        'frequency_interval'           => 1,
        'next_sched_contribution_date' => 'today',
        'start_date'                   => $dateOfFirstContribution,
        'contribution_status_id:name'  => 'In Progress',
        'payment_processor_id:name'    => 'Adyen',
        'is_test'                      => TRUE,
        // @todo Q. what to do re payment instrument ID (I think Adyen, but they might want card/EFT/etc. as Adyen supports various)
      ])
      ->execute()->single()['id'];

    // Create initial contribution.
    $this->cn1ID = civicrm_api3('Contribution', 'create', [
      'contribution_recur_id'  => $this->crID,
      'total_amount'           => 1.23,
      'currency'               => 'EUR',
      'contribution_status_id' => 'Completed', // xxx deprecated?
      'trxn_id'                => 'CiviCRM-test-20220809023627',
      'is_test'                => TRUE,
      'receive_date'           => $dateOfFirstContribution,
      'financial_type_id'      => 1,
      'contact_id'             => $this->contactID,
    ])['id'] ?? NULL;

  }

  public function tearDown():void {
    parent::tearDown();
  }

  /**
   * @dataProvider dataForGeneratePendingContributions
   */
  public function testGeneratePendingContributions(
    string $next_sched_contribution_date,
    string $crStatus,
    array $expected,
    string $repeat = 'nothing'): void {
    // Adjust fixture
    ContributionRecur::update(FALSE)
      ->addValue('contribution_status_id:name', $crStatus)
      ->addValue('next_sched_contribution_date', $next_sched_contribution_date)
      ->addWhere('id', '=', $this->crID)
      ->execute();

    $apiObject = new ProcessAdyen('ContributionRecur', 'processAdyen');
    $apiObject->setCheckPermissions(FALSE);
    $newPending = $apiObject->generatePendingContributions();
    $this->assertIsArray($newPending);

    if ($expected) {
      // We expect a contribution to have been created.
      $this->assertArrayHasKey($this->crID, $newPending, "Expected that a Contribution was created for the ContributionRecur but " . count($newPending) . " contributions created.");
      $newContributionID = $newPending[$this->crID];
      $this->assertNotEquals($this->cn1ID, $newContributionID, "Should be new contribution");

      $order = civicrm_api3('Order', 'get', ['id' => $newContributionID, 'sequential' => 1])['values'][0] ?? FALSE;
      $this->assertIsArray($order, 'Failed to load Order for the new contribution');
      $expectations = [
        'contact_id'            => $this->contactID,
        'total_amount'          => '1.23',
        'contribution_status'   => 'Pending',
        'is_test'               => 1,
        'contribution_recur_id' => $this->crID,
        'financial_type_id'     => 1,
        'trxn_id'               => "CiviCRM-cr{$this->crID}-" . date('Y-m-d'),
      ];
      foreach ($expectations as $key => $value) {
        $this->assertArrayHasKey($key, $order);
        $this->assertEquals($value, $order[$key]);
      }

      // Repeat the call
      $apiObject = new ProcessAdyen('ContributionRecur', 'processAdyen');
      $apiObject->setCheckPermissions(FALSE);
      $newPending = $apiObject->generatePendingContributions();
      $this->assertIsArray($newPending);
      if ($repeat === 'nothing') {
        $this->assertEquals(0, count($newPending), "Expected nothing to happen if we repeated, but something happened!");
      }
      else {
        // $repeat is a date of the 2nd payment.
        $this->assertArrayHasKey($this->crID, $newPending, "Expected that a Contribution was created for the ContributionRecur but " . count($newPending) . " contributions created.");
        $newContributionID = $newPending[$this->crID];
        $this->assertNotEquals($this->cn1ID, $newContributionID, "Should be new contribution");

        $order = civicrm_api3('Order', 'get', ['id' => $newContributionID, 'sequential' => 1])['values'][0] ?? FALSE;
        $this->assertIsArray($order, 'Failed to load Order for the new contribution');
        $expectations = [
          'contact_id'            => $this->contactID,
          'total_amount'          => '1.23',
          'contribution_status'   => 'Pending',
          'is_test'               => 1,
          'contribution_recur_id' => $this->crID,
          'financial_type_id'     => 1,
          'trxn_id'               => "CiviCRM-cr{$this->crID}-" . $repeat
        ];
      }
    }
    else {
      $this->assertEquals(0, count($newPending), 'We did not expect any contributions to be created');
    }
  }
  public function dataForGeneratePendingContributions() {
    $today = date('Y-m-d 00:00:00');
    $yesterday = date('Y-m-d 00:00:00', strtotime('yesterday'));

    $thisMonth = date('Y-m-01');
    $lastMonth = date('Y-m-01', strtotime("$thisMonth - 1 month"));
    
    return [
      'due payment' => [
        $today, 'In Progress', ['receive_date' => $today]
      ],
      'no due payment' => [
        'tomorrow', 'In Progress', []
      ],
      'over due payment' => [
        $yesterday, 'In Progress', ['receive_date' => $yesterday]
      ],
      'due payment on cancelled' => [
        $today, 'Cancelled', []
      ],
      'two due payments' => [
        $lastMonth, 'In Progress', ['receive_date' => $lastMonth], $thisMonth
      ],
    ];

  }
  /**
   * Adding a pending contribution when due.
   */
  public function testPrimaryFunction():void {
    $result = ContributionRecur::processAdyen(FALSE)
      ->execute()->getArrayCopy();
    $this->assertArrayHasKey('newPending', $result);
    $this->assertArrayHasKey($this->crID, $result['newPending'], "Expect that there is a new contribution created for the CR but none was.");
  }

}
