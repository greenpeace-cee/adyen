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

if (!defined('ADYEN_PHPUNIT_TEST')) {
  define('ADYEN_PHPUNIT_TEST', TRUE);
}

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

  /** @var CRM_Core_Payment_Adyen */
  public CRM_Core_Payment_Adyen $testModePaymentProcessorObject;


  /**
   * Holds the primary test ContributionRecur ID
   */
  protected int $contactID;

  /**
   * Holds the payment token ID
   */
  protected int $paymentTokenID;

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
      'payment_instrument_id:name'     => "Credit Card",
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
          ],
          'retryPolicy' => [
            '+1 day', '+1 week', 'skip'
          ]
        ], JSON_PRETTY_PRINT),
      ],
      [
        'is_test'   => 0,
        'user_name' => "fake_live_api_user",
        'password'  => 'fake_live_password',
        'signature' => json_encode([
          'clientKey' => 'live_DUMMYCLIENTKEY',
          'urlPrefix' => '', // only used for live mode.
          'hmacKeys'  => [
            'SECRET_HMAC_KEY_3',
            'SECRET_HMAC_KEY_4',
          ]
        ], JSON_PRETTY_PRINT),
      ],
    ])
    ->execute()->first();

    /** @var CRM_Core_Payment_Adyen */
    $this->testModePaymentProcessorObject = \Civi\Payment\System::singleton()->getByProcessor($this->testModePaymentProcessorConfig);

    // Create a contact, PaymentToken, a ContributionRecur and a completed Contribution, simulating entities created by existing outside processes.
    $this->contactID = Contact::create(FALSE)
      ->setValues(['display_name' => 'Wilma'])->execute()->single()['id'];

    // Create a dummy payment token.
    $this->paymentTokenID = \Civi\Api4\PaymentToken::create(FALSE)
    ->setValues([
      'contact_id' => $this->contactID,
      'payment_processor_id' => $this->testModePaymentProcessorConfig['id'],
      'expiry_date' => date('Ymd', strtotime('now + 1 year')),
      'masked_account_number' => 'visa ... 4242',
      'token' => '1234567890',
    ])
    ->execute()->first()['id'];

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
        'processor_id'                 => 'TEST_SHOPPER_REF',
        'contribution_status_id:name'  => 'In Progress',
        // You cannot set payment_processor_id:name - it picks the live one even though this is_test. So specificy it by ID:
        'payment_processor_id'         => $this->testModePaymentProcessorConfig['id'],
        'is_test'                      => TRUE,
        'payment_instrument_id:name'   => 'Credit Card',
        'payment_token_id'             => $this->paymentTokenID,
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
        'invoice_id'            => "CiviCRM-cn{$newContributionID}-cr{$this->crID}",
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
        $this->assertArrayHasKey($this->crID, $newPending,
          "Expected that only Contribution ID of 0 was returned for the ContributionRecur key $this->crID but "
          . count($newPending) . " other contributions were returned.");
        $this->assertEquals(0, $newPending[$this->crID], "Expected a zero Contribution ID is returned (meaning no contribution created because there is already a pending one).");
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

  /**
   * Provides a way to test the hook.
   */
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

    // string $next_sched_contribution_date,
    // string $crStatus,
    // array $contribution expectations
    // string $expectedNextSchedContributionDate = 'unchanged',
    // string $repeat = 'nothing'): void {
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
      'An In progress CR with two payments overdue should result in a contribution for a month ago, and the next payment should be a month hence.' => [
        // artfulrobot says: The thought here is that (a) this should not occur anyway, and (b) that
        // if someone is to be charged for a month, then they should not be charged twice. This is because
        // as a *donation* it seems unfair from the donor's point of view - better to skip a month for some
        // reason than to be charged twice. Were this for some product or service, it might be more appropriate
        // to make 2 charges; or one for double the amount etc.
        $lastMonth, 'In Progress', ['receive_date' => $lastMonth],
        date('Y-m-d 00:00:00', strtotime("$lastMonth + 2 months")),
        'no_op_warning'
      ],
    ];

  }

  /**
   * Simple test: a payment is due, and successfully submitted. Repeating the call should do nothing.
   *
   */
  public function testPaymentDueAndSuccessful():void {

    $this->mockAdyenCheckoutPayments([]);

    // Call SUT
    $result = ContributionRecur::processAdyen(FALSE)->execute()->getArrayCopy();

    // Check that a contribution was created correctly
    $this->assertArrayHasKey('newPending', $result);
    $this->assertArrayHasKey($this->crID, $result['newPending'], "Expect that there is a new contribution created for the CR but none was.");
    $contrib = Contribution::get(FALSE)
    ->addWhere('id', '=', $result['newPending'][$this->crID])
    ->addWhere('is_test', 'IN', [0, 1])
    ->addSelect('receive_date', 'contribution_status_id:name', 'total_amount', 'trxn_id', 'contribution_recur_id', 'invoice_id', 'invoice_number')
    ->execute()->single();
    $this->assertEquals($this->crID, $contrib['contribution_recur_id']);
    $this->assertEquals('Completed', $contrib['contribution_status_id:name']);
    $this->assertEquals(date('Y-m-d 00:00:00'), $contrib['receive_date']);
    $this->assertEquals(1.23, $contrib['total_amount']);
    // Our Invoice ID is Adyen's merchantReference
    $this->assertEquals("CiviCRM-cn{$contrib['id']}-cr{$this->crID}", $contrib['invoice_id']);
    // Adyen's pspReference is saved as our trxn_id
    $this->assertEquals('DummyPspRef1', $contrib['trxn_id']); // from the payment

    // Check that the recur was updated correctly.
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
    ->addSelect('contribution_status_id:name', 'amount', 'failure_retry_date', 'failure_count', 'cancel_date')
    ->addWhere('id', '=', $this->crID)
    ->execute()->single();
    $this->assertEquals([
      'id' => $this->crID,
      'contribution_status_id:name' => 'In Progress',
      'amount' => 1.23,
      'failure_retry_date' => NULL,
      'failure_count' => 0,
      'cancel_date' => NULL,
    ], $recur);

    // Call SUT again, nothing should be generated.
    $result = ContributionRecur::processAdyen(FALSE)->execute()->getArrayCopy();
    $this->assertEquals(['newPending' => [], 'contributionsProcessed' => []], $result);
  }

  /**
   * Simple test: a payment is due, but it fails
   *
   */
  public function testPaymentDueButFails():void {

    $this->mockAdyenCheckoutPayments(['resultCode' => 'Refused', 'pspReference' => NULL]);

    // Call SUT
    $result = ContributionRecur::processAdyen(FALSE)->execute()->getArrayCopy();

    // Check that a contribution was created correctly
    $this->assertFailedContribution($result);

    // Check that the recur was updated correctly.
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
    ->addSelect('contribution_status_id:name', 'amount', 'failure_retry_date', 'failure_count', 'cancel_date')
    ->addWhere('id', '=', $this->crID)
    ->execute()->single();
    $this->assertEquals([
      'id' => $this->crID,
      'contribution_status_id:name' => 'Failing',
      'amount' => 1.23,
      'failure_retry_date' => date('Y-m-d 00:00:00', strtotime('today +1 day')),
      'failure_count' => 1,
      'cancel_date' => NULL,
    ], $recur);

    // Call SUT again, nothing should be generated.
    $result = ContributionRecur::processAdyen(FALSE)->execute()->getArrayCopy();
    $this->assertEquals(['newPending' => [], 'contributionsProcessed' => []], $result);
  }

  /**
   * Test that when a 2nd payment fails, the next retry is in 1 week's
   * time in accordance with the config (in setup())
   *
   */
  public function testPaymentDueButFails2():void {

    // Adjust the fixture to show one failure, retry due.
    $recur = \Civi\Api4\ContributionRecur::update(FALSE)
    ->addValue('failure_retry_date', date('Y-m-d 00:00:00'))
    ->addValue('failure_count', 1)
    ->addWhere('id', '=', $this->crID)
    ->execute();

    $this->mockAdyenCheckoutPayments(['resultCode' => 'Refused', 'pspReference' => NULL]);

    // Call SUT
    $result = ContributionRecur::processAdyen(FALSE)->execute()->getArrayCopy();

    // Check that a contribution was created correctly
    $this->assertFailedContribution($result);

    // Check that the recur was updated correctly.
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
    ->addSelect('contribution_status_id:name', 'amount', 'failure_retry_date', 'failure_count', 'cancel_date')
    ->addWhere('id', '=', $this->crID)
    ->execute()->single();
    $this->assertEquals([
      'id' => $this->crID,
      'contribution_status_id:name' => 'Failing',
      'amount' => 1.23,
      'failure_retry_date' => date('Y-m-d 00:00:00', strtotime('today +1 week')),
      'failure_count' => 2,
      'cancel_date' => NULL,
    ], $recur);

    // Call SUT again, nothing should be generated.
    $result = ContributionRecur::processAdyen(FALSE)->execute()->getArrayCopy();
    $this->assertEquals(['newPending' => [], 'contributionsProcessed' => []], $result);
  }

  /**
   * Test that when a 3rd payment fails, there is no retry, but the CR is still not failed
   * time in accordance with the config (in setup())
   */
  public function testPaymentDueButFailsSkipped():void {

    // Adjust the fixture to show two failures, retry due.
    $recur = \Civi\Api4\ContributionRecur::update(FALSE)
    ->addValue('failure_retry_date', date('Y-m-d 00:00:00'))
    ->addValue('failure_count', 2)
    ->addWhere('id', '=', $this->crID)
    ->execute();

    $this->mockAdyenCheckoutPayments(['resultCode' => 'Refused', 'pspReference' => NULL]);

    // Call SUT
    $result = ContributionRecur::processAdyen(FALSE)->execute()->getArrayCopy();

    // Check that a contribution was created correctly
    $this->assertFailedContribution($result);

    // Check that the recur was updated correctly.
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
    ->addSelect('contribution_status_id:name', 'amount', 'failure_retry_date', 'failure_count', 'cancel_date')
    ->addWhere('id', '=', $this->crID)
    ->execute()->single();
    $this->assertEquals([
      'id' => $this->crID,
      'contribution_status_id:name' => 'Failing',
      'amount' => 1.23,
      'failure_retry_date' => NULL,
      'failure_count' => 3,
      'cancel_date' => NULL,
    ], $recur);

    // Call SUT again, nothing should be generated.
    $result = ContributionRecur::processAdyen(FALSE)->execute()->getArrayCopy();
    $this->assertEquals(['newPending' => [], 'contributionsProcessed' => []], $result);
  }

  /**
   * Test the 'fail' retry policy.
   */
  public function testPaymentDueButFailsFailed():void {

    // Adjust the config to not allow retries, and immediately fail the CR.
    $jsonConfig = json_decode($this->testModePaymentProcessorConfig['signature'], TRUE);
    $jsonConfig['retryPolicy'] = ['fail'];
    $this->testModePaymentProcessorConfig = \Civi\Api4\PaymentProcessor::update(FALSE)
    ->setReload(TRUE)
    ->addWhere('id', '=',$this->testModePaymentProcessorObject->getID())
    ->addValue('signature', json_encode($jsonConfig, JSON_PRETTY_PRINT))
    ->execute()->single();
    \Civi\Payment\System::singleton()->flushProcessors();
    $this->testModePaymentProcessorObject = \Civi\Payment\System::singleton()->getByProcessor($this->testModePaymentProcessorConfig);

    // Mock the checkouts API
    $this->mockAdyenCheckoutPayments(['resultCode' => 'Refused', 'pspReference' => NULL]);

    // Call SUT
    $result = ContributionRecur::processAdyen(FALSE)->execute()->getArrayCopy();

    // Check that a contribution was created correctly
    $this->assertFailedContribution($result);

    // Check that the recur was updated correctly.
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
    ->addSelect('contribution_status_id:name', 'failure_retry_date', 'failure_count', 'cancel_date')
    ->addWhere('id', '=', $this->crID)
    ->execute()->single();
    $this->assertLessThanOrEqual(2, time() - strtotime($recur['cancel_date']), "Cancel date should have been set to now (allowing 2s)");
    unset($recur['cancel_date']);

    $this->assertEquals([
      'id' => $this->crID,
      'contribution_status_id:name' => 'Failed',
      'failure_retry_date' => NULL,
      'failure_count' => 1,
    ], $recur);

    // Call SUT again, nothing should be generated.
    $result = ContributionRecur::processAdyen(FALSE)->execute()->getArrayCopy();
    $this->assertEquals(['newPending' => [], 'contributionsProcessed' => []], $result);
  }

  protected function assertFailedContribution(array $result) {
    $this->assertArrayHasKey('newPending', $result);
    $this->assertArrayHasKey($this->crID, $result['newPending'], "Expect that there is a new contribution created for the CR but none was.");
    $contrib = Contribution::get(FALSE)
    ->addWhere('id', '=', $result['newPending'][$this->crID])
    ->addWhere('is_test', 'IN', [0, 1])
    ->addSelect('receive_date', 'contribution_status_id:name', 'total_amount', 'trxn_id', 'contribution_recur_id', 'invoice_id', 'invoice_number')
    ->execute()->single();
    $this->assertEquals($this->crID, $contrib['contribution_recur_id']);
    $this->assertEquals('Failed', $contrib['contribution_status_id:name']);
    $this->assertEquals(date('Y-m-d 00:00:00'), $contrib['receive_date']);
    $this->assertEquals(1.23, $contrib['total_amount']);
    // Our Invoice ID is Adyen's merchantReference
    $this->assertEquals("CiviCRM-cn{$contrib['id']}-cr{$this->crID}", $contrib['invoice_id']);
    // Adyen's pspReference is normally saved as our trxn_id, but not when it fails.
    $this->assertEmpty($contrib['trxn_id']);

  }

  protected function mockAdyenCheckoutPayments(array $result) {
    $mockConfig = $this->createMock(\Adyen\Config::class);
    $map = [
      ['environment', \Adyen\Environment::TEST],
      ['endpointCheckout', 'https://mock-endpoint.localhost'],
    ];
    $mockConfig->method('get')->willReturnMap($map);
    $mock = $this->createMock(\Adyen\Client::class);
    $mock->method('getConfig')->willReturn($mockConfig);
    $this->testModePaymentProcessorObject->setMockAdyenClient($mock);

    $this->testModePaymentProcessorObject->mocks = [
      \Adyen\Service\Checkout::class => function($constructorArgs) use ($result) {
        $m = $this->createMock(\Adyen\Service\Checkout::class);
        $m->method('payments')
          ->willReturn($result + [
            'resultCode' => 'Authorized',
            'pspReference' => 'DummyPspRef1',
          ]);
        return $m;
      }
    ];
  }

}
