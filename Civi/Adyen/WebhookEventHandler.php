<?php
namespace Civi\Adyen;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\Email;
use StdClass;

/**
 * This class encapsulates a single webhook event and knows how to process it.
 */
class WebhookEventHandler {

  /**
   * The raw event array
   */
  protected array $eventData;

  /**
   * The CiviCRM contact ID that maps to the customer
   *
   * @var int
   */
  protected $contactID = NULL;

  // Properties of the event.

  /**
   * @var string The date/time the charge was made
   */
  protected $receive_date = NULL;

  /**
   * @var float The amount paid
   */
  protected $amount = 0.0;

  /**
   * @var float The fee charged
   */
  protected $fee = 0.0;

  /**
   * @var array The current contribution
   */
  protected $contribution = NULL;

  /**
   * Create a new handler.
   */
  public function __construct(array $eventData) {
    if (empty($eventData['eventCode'])) {
      throw new \InvalidArgumentException("Adyen events must contain a 'eventCode' key holding the event type.");
    }

    $this->eventData = $eventData;
  }

  /**
   * Main calling method.
   */
  public function run(): StdClass {

    $return = (object) [
      'ok' => TRUE,
      'message' =>  '',
      'exception' => NULL
    ];
    try {
      switch ($this->eventData['eventCode']) {
      case 'AUTHORISATION':
        $return->message = $this->processAuthorisationEvent();
        break;

      default:
        throw new WebhookEventIgnoredException(ts('Event ignored (normal - we do not process "%1" events)', [1 => $this->eventData['eventCode']]));
      }
    }
    catch (WebhookEventIgnoredException $e) {
      $return->message = $e->getMessage();
    }
    return $return;
  }

  /**
   * Get the Contribution TrxnID from the notification
   * @param array $event
   *
   * @return mixed
   * @throws \Exception
   */
  protected function getContributionTrxnIDFromEvent(): string {
    if (empty($this->eventData['merchantReference'])) {
      throw new \InvalidArgumentException('No merchantReference found in webhook event payload');
    }
    return $this->eventData['merchantReference'];
  }

  /**
   * @param array $event
   *
   * @return float
   */
  public function getAmountFromEvent(): float {
    return ((float) $this->eventData['amount']['value']) / 100;
  }

  /**
   * @param array $event
   *
   * @return string
   */
  private function getCurrencyFromEvent(): string {
    return $this->eventData['amount']['currency'];
  }

  /**
   * Get the contact ID from the event using the shopperEmail / shopperName
   * Match to existing contact or create new contact with the details provided
   *
   * Sets $this->contactID
   */
  public function identifyContact() {
    $event = $this->eventData;
    $email = $event['additionalData']['shopperEmail'] ?? NULL;
    //  [shopperName] => [first name=Ivan, infix=null, last name=Velasquez, gender=null]
    preg_match('/\[first name=([^,]*)/', $event['additionalData']['shopperName'] ?? '', $firstName);
    $firstName = $firstName[1] ?? NULL;
    preg_match('/\last name=([^,]*)/', $event['additionalData']['shopperName'] ?? '' ?? '', $lastName);
    $lastName = $lastName[1] ?? NULL;

    $contact = Contact::get(FALSE)
      ->addWhere('contact_type:name', '=', 'Individual');
    if (!empty($firstName)) {
      $contact->addWhere('first_name', '=', $firstName);
    }
    if (!empty($lastName)) {
      $contact->addWhere('last_name', '=', $lastName);
    }
    if (!empty($email)) {
      $contact->addJoin('Email AS email', 'LEFT');
      $contact->addWhere('email.email', '=', $email);
    }
    $contact = $contact->execute()->first();
    if (!empty($contact)) {
      return $contact['id'];
    }

    $newContact = Contact::create(FALSE)
      ->addValue('contact_type:name', 'Individual');
    if (!empty($firstName)) {
      $newContact->addValue('first_name', $firstName);
    }
    if (!empty($lastName)) {
      $newContact->addValue('last_name', $lastName);
    }
    $contact = $newContact->execute()->first();

    if (!empty($email)) {
      Email::create(FALSE)
        ->addValue('contact_id', $contact['id'])
        ->addValue('location_type_id:name', 'Billing')
        ->addValue('email', $email)
        ->execute();
    }
    $this->contactID = $contact['id'];
  }
  /**
   * Handle the "AUTHORISATION" webhook notification
   * @see https://docs.adyen.com/api-explorer/#/Webhooks/latest/post/AUTHORISATION
   * This creates a pending contribution in CiviCRM if it does not already exist
   */
  public function processAuthorisationEvent() :string {

    $trxnID = $this->getContributionTrxnIDFromEvent();
    // The authorization for the card was not successful so we ignore it.
    if (empty($this->eventData['success'])) {
      throw new WebhookEventIgnoredException('Ignoring Authorization not successful for merchant Reference: ' . $trxnID);
    }

    // The authorization for the card was successful so we create a pending contribution if we don't already have one.
    $contribution = Contribution::get(FALSE)
      ->addWhere('trxn_id', '=', $trxnID)
      ->addWhere('is_test', 'IN', [0, 1])
      ->execute()
      ->first();
    if (!empty($contribution)) {
      throw new WebhookEventIgnoredException(ts('Ignoring webhook event for trxn_id %1 because it looks like it has already been processed as Contribution ID %2.', [
        1 => $trxnID,
        2 => $contribution['id'],
      ]));
    }

    $this->identifyContact();

    $contribution = Contribution::create(FALSE)
      ->addValue('total_amount', $this->getAmountFromEvent())
      ->addValue('currency', $this->getCurrencyFromEvent())
      ->addValue('contribution_status_id:name', 'Pending')
      ->addValue('trxn_id', $trxnID)
      ->addValue('contact_id', $this->contactID)
      // @fixme: This should probably be configurable
      ->addValue('financial_type_id.name', 'Donation')
      ->addValue('payment_instrument_id:name', 'Credit Card')
      ->execute()
      ->first();
    return 'OK. Contribution created with ID: ' . $contribution['id'];
  }
}

