<?php

use CRM_Adyen_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Core\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\Test\CiviEnvBuilder;

/**
 * FIXME - Add test description.
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
   * Creates a test-mode payment processor (everything is fake)
   */
  public function setUp():void {
    parent::setUp();

    $this->testModePaymentProcessorConfig = \Civi\Api4\PaymentProcessor::save(FALSE)
    ->setReload(TRUE)
    ->setDefaults([
      'payment_processor_type_id:name' => "Adyen",
      'name' => "Adyen",
      'class_name' => 'Payment_Adyen',
      'billing_mode' => 1, // ?
      'description' => "Set up by test script",
      'is_active' => 1,
      'is_recur' => 1,
      'payment_type' => 1,
      'url_api' => 'http://notused.example.org',
      'payment_instrument_id:name' => "Credit Card",
      'domain_id' => 1,
    ])
    ->setRecords([
      [
        'is_test' => 1,
        'user_name' => "fake_test_api_user",
        'password' => 'fake_password',
        'signature' => json_encode([
          'clientKey' => 'test_DUMMYCLIENTKEY',
          'urlPrefix' => '', // only used for live mode.
          'hmacKeys' => [
            'SECRET_HMAC_KEY_1',
            'SECRET_HMAC_KEY_2',
          ]
        ], JSON_PRETTY_PRINT),
      ],
      [
        'is_test' => 0,
        'user_name' => "fake_live_api_user",
        'password' => 'fake_live_password',
        'signature' => json_encode([
          'clientKey' => 'live_DUMMYCLIENTKEY',
          'urlPrefix' => 'https://something.example.org', // only used for live mode.
          'hmacKeys' => [
            'SECRET_HMAC_KEY_3',
            'SECRET_HMAC_KEY_4',
          ]
        ], JSON_PRETTY_PRINT),
      ],
    ])
    ->execute()->first();

    $this->testModePaymentProcessorObject = \Civi\Payment\System::singleton()->getByProcessor($this->testModePaymentProcessorConfig);
  }

  public function tearDown():void {
    parent::tearDown();
  }

  /**
   * Test that an event with an unknown eventCode is safely ignored.
   */
  public function testIgnoredEventType():void {
    $eventData = $this->loadFixtureData('webhook-ignored-type.json');
    $handler = new \Civi\Adyen\WebhookEventHandler($eventData);
    $result = $handler->run();
    $this->assertEquals('success', $result->status);
    $this->assertEquals('Event ignored (normal - we do not process "SOME_NON_REQUIRED_TYPE" events)', $result->message);
  }

  /**
   * Test that a successful new AUTHORISED webhook results in a new Completed contribution.
   */
  public function testAuthorisedSuccess():void {
    $eventData = $this->loadFixtureData('webhook-authorised.json');
    $handler = new \Civi\Adyen\WebhookEventHandler($eventData);
    $result = $handler->run();
    $this->assertInstanceOf(stdClass::class, $result);
    $this->assertEquals('success', $result->status);
    $this->assertNull($result->exception);

    $this->assertEquals(1, preg_match('/^OK\. Created new contribution (\d+), invoice_id civi-mock-ref-1, trxn_id TQ9J3F3J7G9WHD82\./', $result->message, $matches));

    // Load the contribution to check it is Completed.
    $cn = \Civi\Api4\Contribution::get(FALSE)
    ->addSelect('id', 'contribution_status_id:name')
    ->addWhere('id', '=', $matches[1])
    ->execute()->single();
    $this->assertEquals('Completed', $cn['contribution_status_id:name']);
  }

  /**
   * Test that a successful AUTHORISED webhook firing against a contribution we already updates expiry and card details.
   *
   */
  public function testAuthorisedUpdate():void {

    $eventData = $this->loadFixtureData('webhook-authorised.json');

    // Create a contact, PaymentToken, a ContributionRecur and a completed Contribution, simulating entities created by existing outside processes.
    $contactID = \Civi\Api4\Contact::create(FALSE)
      ->setValues(['display_name' => 'Wilma'])->execute()->single()['id'];

    // Create a dummy payment token.
    $paymentTokenID = \Civi\Api4\PaymentToken::create(FALSE)
    ->setValues([
      'contact_id' => $contactID,
      'payment_processor_id' => $this->testModePaymentProcessorConfig['id'],
      'expiry_date' => date('Ymd', strtotime('now + 1 year')),
      'masked_account_number' => 'visa ... 4242',
      'token' => '1234567890',
    ])
    ->execute()->first()['id'];

    $crID = \Civi\Api4\ContributionRecur::create(FALSE)
      ->setValues([
        'contact_id'                   => $contactID,
        'amount'                       => '1.23',
        'currency'                     => 'EUR',
        'financial_type_id'            => 1,
        'frequency_unit'               => 'month',
        'frequency_interval'           => 1,
        'next_sched_contribution_date' => 'today',
        'start_date'                   => 'today',
        'processor_id'                 => 'TEST_SHOPPER_REF',
        'contribution_status_id:name'  => 'In Progress',
        // You cannot set payment_processor_id:name - it picks the live one even though this is_test. So specificy it by ID:
        'payment_processor_id'         => $this->testModePaymentProcessorConfig['id'],
        'is_test'                      => TRUE,
        'payment_instrument_id:name'   => 'Credit Card',
        'payment_token_id'             => $paymentTokenID,
      ])
      ->execute()->single()['id'];


    // Create initial contribution.
    $cnID = civicrm_api3('Contribution', 'create', [
      'contribution_recur_id'  => $crID,
      'total_amount'           => 1.23,
      'currency'               => 'EUR',
      'contribution_status_id' => 'Completed', // xxx deprecated?
      'invoice_id'             => $eventData['merchantReference'],
      'trxn_id'                => $eventData['pspReference'],
      'is_test'                => TRUE,
      'receive_date'           => 'today',
      'financial_type_id'      => 1,
      'contact_id'             => $contactID,
    ])['id'] ?? NULL;


    // Call webhook
    $handler = new \Civi\Adyen\WebhookEventHandler($eventData);
    $result = $handler->run();
    $this->assertInstanceOf(stdClass::class, $result);
    $this->assertEquals('success', $result->status);
    $this->assertNull($result->exception);

    $this->assertStringStartsWith("OK. Matched existing contribution $cnID, invoice_id civi-mock-ref-1, trxn_id TQ9J3F3J7G9WHD82. Updated payment token details", $result->message);

    // Load the payment token to check it has been updated.
    $token = \Civi\Api4\PaymentToken::get(FALSE)
    ->addWhere('id', '=', $paymentTokenID)
    ->execute()->single();
    $this->assertEquals('visa ... 1142', $token['masked_account_number']);
    $this->assertEquals('2030-03-31 23:59:00', $token['expiry_date']);

    // Repeat the webhook call - no updates should occur.
    $handler = new \Civi\Adyen\WebhookEventHandler($eventData);
    $result = $handler->run();
    $this->assertInstanceOf(stdClass::class, $result);
    $this->assertEquals('success', $result->status);
    $this->assertNull($result->exception);

    $this->assertEquals("OK. Matched existing contribution $cnID, invoice_id civi-mock-ref-1, trxn_id TQ9J3F3J7G9WHD82.", $result->message);
  }

  /**
   * Test that an AUTHORISED webhook with unmatchedContributionBehaviour=retry does not create new contributions
   */
  public function testAuthorisedRetry(): void {
    $eventData = $this->loadFixtureData('webhook-authorised.json');
    $handler = new \Civi\Adyen\WebhookEventHandler($eventData, ['unmatchedContributionBehaviour' => 'retry']);
    $result = $handler->run();
    $this->assertInstanceOf(stdClass::class, $result);
    $this->assertEquals('new', $result->status);
    $this->assertNull($result->exception);

    $cn = \Civi\Api4\Contribution::get(FALSE)
      ->selectRowCount()
      ->addWhere('invoice_id', '=', 'civi-mock-ref-1')
      ->execute();
    $this->assertEquals(0, $cn->count(), 'Should not create contribution');
  }

  protected function loadFixtureData(string $filename) :array {
    $data = json_decode(file_get_contents(__DIR__ . '/fixtures/' . $filename), TRUE);
    if (!$data) {
      throw new \InvalidArgumentException("Failed to load fixture '$filename");
    }
    return $data;
  }

}
