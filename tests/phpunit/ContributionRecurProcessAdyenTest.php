<?php

// use CRM_Adyen_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Core\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\Test\CiviEnvBuilder;
use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Contribution;
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
class ContributionRecurProcessAdyenTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {


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
   * Holds an override date; if set then our listener on_civi_recur_nextschedcontributiondatealter
   * will set the event's new date to this value.
   */
  protected ?string $overrideNextSchedDate;

  protected bool $hookWasCalled = FALSE;
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

  /**
   * Creates an Adyen payment processor,
   * a contact, a ContributionRecur with a completed Contribution, simulating entities
   * created by existing outside processes.
   *
   * The contribution is a month ago, and the recur is due today.
   */
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

    // @todo cycle_day ?
    $this->crID = ContributionRecur::create(FALSE)
      ->setValues([
        'contact_id'                   => $this->contactID,
        'amount'                       => '1.23',
        'currency'                     => 'EUR',
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
   * This tests the generatePendingContributions method, which is called
   * internally by the ContributionRecur.ProcessAdyen API action.
   *
   * Testing it directly like this enables us to make more fine grained assertions.
   *
   * @dataProvider dataForGeneratePendingContributions
   *
   * @var string $expectedNextSchedContributionDate
   *    'unchanged' means same as $next_sched_contribution_date
   *    '=2022-01-02' The = prefix means that we will set our overrideNextSchedDate to this date, and then expect it.
   *    '2022-01-02' Expect this date, without using our hook override.
   */
  public function testGeneratePendingContributions(
    string $next_sched_contribution_date,
    string $crStatus,
    array $expected,
    string $expectedNextSchedContributionDate = 'unchanged',
    string $repeat = 'nothing'): void {

    // Adjust fixture
    $next_sched_contribution_date = date('Y-m-d 00:00:00', strtotime($next_sched_contribution_date));
    ContributionRecur::update(FALSE)
      ->addValue('contribution_status_id:name', $crStatus)
      ->addValue('next_sched_contribution_date', $next_sched_contribution_date)
      ->addWhere('id', '=', $this->crID)
      ->execute();

    if ((substr($expectedNextSchedContributionDate, 0, 1) === '=')) {
      $expectedNextSchedContributionDate = substr($expectedNextSchedContributionDate, 1);
      $this->overrideNextSchedDate = $expectedNextSchedContributionDate;
    }
    else {
      $this->overrideNextSchedDate = NULL;
    }

    $this->hookWasCalled = FALSE;
    $apiObject = new ProcessAdyen('ContributionRecur', 'processAdyen');
    $apiObject->setCheckPermissions(FALSE);
    $newPending = $apiObject->generatePendingContributions();
    $this->assertIsArray($newPending);

    if ($expected) {
      $this->assertTrue($this->hookWasCalled, 'Hook should have been called.');
      // We expect a contribution to have been created.
      $this->assertArrayHasKey($this->crID, $newPending, "Expected that a Contribution was created for the ContributionRecur but " . count($newPending) . " contributions created.");
      $newContributionID = $newPending[$this->crID];
      $this->assertNotEquals($this->cn1ID, $newContributionID, "Should be new contribution");

      $order = civicrm_api3('Order', 'get', ['id' => $newContributionID, 'sequential' => 1])['values'][0] ?? FALSE;
      $this->assertIsArray($order, 'Failed to load Order for the new contribution');
      $expectations = $expected + [
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
        $this->assertEquals($value, $order[$key], "$key differs in contribution");
      }

      // Repeat the call
      $apiObject = new ProcessAdyen('ContributionRecur', 'processAdyen');
      $apiObject->setCheckPermissions(FALSE);
      $newPending = $apiObject->generatePendingContributions();
      $this->assertIsArray($newPending);
      if ($repeat === 'nothing') {
        $this->assertEquals(0, count($newPending), "Expected nothing to happen if we repeated, but something happened!");
      }
      elseif ($repeat === 'no_op_warning') {
        $this->assertArrayHasKey($this->crID, $newPending, "Expected that a Contribution ID of 0 was returned for the ContributionRecur key $this->crID but " . count($newPending) . " contributions returned without that key.");
        $this->assertEquals(0, $newPending[$this->crID], "Expected a zero Contribution ID is returned.");
      }
      else {
        throw new \InvalidArgumentException("'$repeat' is not one of nothing|no_op_warning; this is a bug in the test case code.");
      }
    }
    else {
      $this->assertEquals(0, count($newPending), 'We did not expect any contributions to be created');
    }

    if ($expectedNextSchedContributionDate === 'unchanged') {
      $expectedNextSchedContributionDate = $next_sched_contribution_date;
    }
    $next_sched_contribution_date = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $this->crID)
      ->addSelect('next_sched_contribution_date')
      ->execute()->single()['next_sched_contribution_date'] ?? NULL;
    $this->assertEquals(substr($expectedNextSchedContributionDate, 0, 10), substr($next_sched_contribution_date, 0, 10), "Next scheduled date was not as expected.");
  }

  public function on_civi_recur_nextschedcontributiondatealter(\Civi\Core\Event\GenericHookEvent $event) {
    $this->hookWasCalled = TRUE;
    if (!empty($this->overrideNextSchedDate)) {
      $event->newDate = $this->overrideNextSchedDate;
    }
  }

  public function dataForGeneratePendingContributions() {
    $today = date('Y-m-d 00:00:00');
    $yesterday = date('Y-m-d 00:00:00', strtotime('yesterday'));

    $thisMonth = date('Y-m-01');
    $lastMonth = date('Y-m-01 00:00:00', strtotime("$thisMonth - 1 month"));
    
    return [
      'A due, In progress CR, should result in a contribution today.' => [
        $today, 'In Progress', ['receive_date' => $today], 
        date('Y-m-d 00:00:00', strtotime('today + 1 month'))
      ],
      'Default next_sched_contribution_date calculation' => [
        $today, 'In Progress', ['receive_date' => $today], 
        date('Y-m-d 00:00:00', strtotime('today + 1 month'))
      ],
      'Overridden next_sched_contribution_date calculation' => [
        $today, 'In Progress', ['receive_date' => $today], 
        '='. date('Y-m-d 00:00:00', strtotime('today + 6 weeks'))
      ],
      'An In progress CR due tomorrow, should not result in a new contribution.' => [
        'tomorrow', 'In Progress', []
      ],
      'An Overdue CR due yesterday, should result in a contribution yesterday' => [
        $yesterday, 'In Progress', ['receive_date' => $yesterday],
        date('Y-m-d 00:00:00', strtotime('yesterday + 1 month'))
      ],
      'An Cancelled CR should not result in a new contribution' => [
        $today, 'Cancelled', []
      ],
      'An In progress CR with two payments due should ??? @todo' => [
        $lastMonth, 'In Progress', ['receive_date' => $lastMonth], 
        date('Y-m-d 00:00:00', strtotime("$lastMonth + 2 months")),
        'no_op_warning'
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
    $contrib = Contribution::get(FALSE)
    ->addWhere('id', '=', $result['newPending'][$this->crID])
    ->addWhere('is_test', 'IN', [0, 1])
    ->addSelect('receive_date', 'contribution_status_id:name', 'total_amount', 'trxn_id', 'contribution_recur_id', 'invoice_id', 'invoice_number')
    ->execute()->single();

    $this->assertEquals($this->crID, $contrib['contribution_recur_id']);
    $this->assertEquals('Pending', $contrib['contribution_status_id:name']);
    $this->assertEquals(date('Y-m-d 00:00:00'), $contrib['receive_date']);
    $this->assertEquals(1.23, $contrib['total_amount']);
    $this->assertEquals("CiviCRM-cr{$this->crID}-" . date('Y-m-d'), $contrib['trxn_id']);
    $this->assertEquals('', $contrib['invoice_id']); // machine
    $this->assertEquals('', $contrib['invoice_number']); // human readable

    // Call again, nothing should be generated.
    $result = ContributionRecur::processAdyen(FALSE)->execute()->getArrayCopy();
    $this->assertArrayHasKey('newPending', $result);
    $this->assertCount(0, $result['newPending']);
  }

}
