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
    $this->assertTrue($result->ok);
    $this->assertEquals('Event ignored (normal - we do not process "SOME_NON_REQUIRED_TYPE" events)', $result->message);
  }

  /**
   * Test that a successful AUTHORISED webhook results in a Pending contribution.
   */
  public function testAuthorisedSuccess():void {
    $eventData = $this->loadFixtureData('webhook-authorised.json');
    $handler = new \Civi\Adyen\WebhookEventHandler($eventData);
    $result = $handler->run();
    $this->assertInstanceOf(stdClass::class, $result);
    $this->assertTrue($result->ok);
    $this->assertNull($result->exception);
    $this->assertEquals(1, preg_match('/^OK\. Contribution created with ID: (\d+)$/', $result->message, $matches));

    // Load the contribution.
    $cn = \Civi\Api4\Contribution::get(FALSE)
    ->addSelect('id', 'contribution_status_id:name')
    ->addWhere('id', '=', $matches[1])
    ->execute()->single();
    $this->assertEquals('Pending', $cn['contribution_status_id:name']);
  }

  protected function loadFixtureData(string $filename) :array {
    $data = json_decode(file_get_contents(__DIR__ . '/fixtures/' . $filename), TRUE);
    if (!$data) {
      throw new \InvalidArgumentException("Failed to load fixture '$filename");
    }
    return $data;
  }

}
