<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

use Civi\Adyen\WebhookException;
use Civi\Api4\PaymentprocessorWebhook;
use Civi\Adyen\WebhookEventHandler;

/**
 * Class CRM_Core_Payment_AdyenIPN
 */
class CRM_Core_Payment_AdyenIPN {

  // use CRM_Core_Payment_MJWIPNTrait;

  /**
   * @var \CRM_Core_Payment_Adyen Payment processor
   */
  protected $_paymentProcessor;

  public function __construct(?CRM_Core_Payment_Adyen $paymentObject = NULL) {
    $this->_paymentProcessor = $paymentObject;
  }

  /**
   * Returns TRUE if we handle this event type, FALSE otherwise
   * @param string $eventType
   *
   * @return bool
   */
  public function setEventType($eventType) {
    $this->eventType = $eventType;
    if (!in_array($this->eventType, CRM_Adyen_Webhook::getDefaultEnabledEvents())) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Set and initialise the paymentProcessor object
   * @param int $paymentProcessorID
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function setPaymentProcessor($paymentProcessorID) {
    try {
      $this->_paymentProcessor = \Civi\Payment\System::singleton()->getById($paymentProcessorID);
    }
    catch (Exception $e) {
      throw new \RuntimeException('Failed to get payment processor: ' . $e->getMessage());
    }
  }

  /**
   * Check incoming input for validity and extract the data.
   *
   * Alters $this->events and sets
   * $this->paymentProcessorObject unless already set.
   *
   * http request
   *   -> paymentClass::handlePaymentNotification()
   *     -> this::handleRequest()
   *       -> parseWebhookRequest()
   *
   * @throws Civi\Adyen\WebhookException if signature does not match.
   *
   * @param string $raw_payload
   *
   * @return void
   * @throws \Adyen\AdyenException
   */
  public function parseWebhookRequest($rawPayload) {
    $payload = json_decode($rawPayload, TRUE);
    \Civi::log()->debug('payload: ' . json_encode($payload, JSON_PRETTY_PRINT));

    if (empty($payload['notificationItems']) || !is_array($payload['notificationItems'])) {
      throw new WebhookException('Invalid notification payload: notificationItems is empty');
    }

    $this->events = [];

    foreach ($payload['notificationItems'] as $item) {
      if (empty($item['NotificationRequestItem'])) {
        throw new WebhookException('Invalid notification payload: is empty');
      }
      $item = $item['NotificationRequestItem'];
      // @todo Filter event codes for ones we support?
      // switch ($item['eventCode']) {

      if (empty($item['additionalData']['hmacSignature'])) {
        throw new WebhookException('Invalid notification: no HMAC signature provided');
      }

      // verify HMAC
      $hmacValid = FALSE;
      $sig = new \Adyen\Util\HmacSignature();
      // iterate through all enabled HMAC keys and find one that verifies
      // we need to support multiple HMAC keys to support rotation
      foreach ($this->getPaymentProcessor()->getHMACKeys() as $hmacKey) {
        \Civi::log()->debug("Trying key $hmacKey");
        $hmacValid = $sig->isValidNotificationHMAC($hmacKey, $item);
        if ($hmacValid) {
          // we found a valid signature, done
          break;
        }
      }

      if (!$hmacValid) {
        throw new WebhookException('Invalid notification: HMAC verification failed');
      }

      if ($this->getPaymentProcessor()->getMerchantAccount() !== $item['merchantAccountCode']) {
        \Civi::log()->debug('MerchantAccountCode ' . $item['merchantAccountCode'] . ' does not match the configured code CiviCRM - ignoring');
        continue;
      }

      $this->events[] = $item;
    }
  }

  /**
   * Get the payment processor object
   */
  public function getPaymentProcessor() :CRM_Core_Payment_Adyen {
    return $this->_paymentProcessor;
  }

  /**
   * Handles the incoming http webhook request and returns a suitable http response code.
   */
  public function handleRequest(array $headers, string $body) :int {
    // This verifies HMAC signatures and sets $this->events to an array of "parsed" notifications
    try {
      $this->parseWebhookRequest($body);

      $records = [];
      foreach ($this->events as $event) {
        // Nb. we set trigger and identifier here mostly to help when troubleshooting.
        $records[] = [
          'event_id'   => $event['eventDate'],
          'trigger'    => $event['eventCode'],
          'identifier' => $event['pspReference'], // We don't strictly need this but it might be useful for troubleshooting
          'data'       => json_encode($event),
        ];
      }
      if ($records) {
        // Store the events. They will receive status 'new'. Note that
        // because we filter out events we don't need, there may not be any
        // records to record.
        \Civi\Api4\PaymentprocessorWebhook::save(FALSE)
        ->setCheckPermissions(FALSE) // Remove line when minversion>=5.29
        ->setRecords($records)
        ->setDefaults(['payment_processor_id' => $this->getPaymentProcessor()->getID(), 'created_date' => 'now'])
        ->execute();
      }

      \Civi::log()->info("OK: " . count($records) . " webhook events queued for background processing.");
      return 200;
    }
    catch (\Exception $e) {

      $publicSafeMessage = $e->publicSafeMessage ?? '';

      // Handle exceptions a bit nicer. No IPN calling thing wants to receive a crappy html page...
      \Civi::log()->error("Exception in CRM_Core_Payment_AdyenIPN: "
        . $e->getMessage() . " on line " . $e->getLine() . " of " . $e->getFile(),
        ['publicSafeMessage' => $publicSafeMessage, 'exception' => $e]);
      $body = json_encode(['error' => $publicSafeMessage]);
      http_response_code(500);
      header('Content-Type: application/json');
      echo $body;
      exit();
    }
  }

  /**
   * Process a single queued event and update it.
   *
   * @param array $webhookEvent
   *
   * @return bool TRUE on success.
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function processQueuedWebhookEvent(array $webhookEvent) :bool {

    $eventData = json_decode($webhookEvent['data'], TRUE);
    $processor = new WebhookEventHandler($eventData);
    $processingResult = $processor->run();

    // Update the stored webhook event.
    PaymentprocessorWebhook::update(FALSE)
      ->addWhere('id', '=', $webhookEvent['id'])
      ->addValue('status', $processingResult->ok ? 'success' : 'error')
      ->addValue('message', preg_replace('/^(.{250}).*/su', '$1 ...', $processingResult->message))
      ->addValue('processed_date', 'now')
      ->execute();

    return $processingResult->ok;
  }
}
