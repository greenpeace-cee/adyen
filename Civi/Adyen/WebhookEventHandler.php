<?php
namespace Civi\Adyen;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\Email;
use http\Exception\InvalidArgumentException;
use StdClass;

/**
 * This class encapsulates a single webhook event as found at
 * notificationItems[n].NotificationRequestItem
 * and knows how to process it.
 *
 */
class WebhookEventHandler {

  /**
   * The raw event array
   */
  protected array $eventData;

  /**
   * @var array PaymentProcessor extra config
   */
  protected array $extraConfig;

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
  public function __construct(array $eventData, array $extraConfig = []) {
    if (empty($eventData['eventCode'])) {
      throw new \InvalidArgumentException("Adyen events must contain a 'eventCode' key holding the event type.");
    }

    $this->eventData = $eventData;
    $this->extraConfig = $extraConfig;
  }

  /**
   * Main calling method.
   */
  public function run(): StdClass {

    $return = (object) [
      'status' => 'success',
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
    catch (WebhookEventRetryException $e) {
      $return->status = 'new';
      $return->message = $e->getMessage();
    }
    catch (\Exception $e) {
      $return->status = 'error';
      $return->message = $e->getMessage();
      $return->exception = $e;
    }
    return $return;
  }

  /**
   * Get the Contribution/Payment TrxnID from the notification
   *
   * Adyen.pspReference === Payment.trxn_id
   *
   * and typically,
   * Adyen.pspReference === Contribution.trxn_id
   *
   * @param array $event
   *
   * @return mixed
   * @throws \Exception
   */
  protected function getPaymentTrxnIDFromEvent(): string {
    if (empty($this->eventData['pspReference'])) {
      throw new \InvalidArgumentException('No pspReference found in webhook event payload');
    }
    return $this->eventData['pspReference'];
  }

  /**
   * Get the Contribution InvoiceID from the Adyen notification
   *
   * Adyen.merchantReference === Contribution.invoice_id
   *
   * @param array $event
   *
   * @return mixed
   * @throws \Exception
   */
  protected function getContributionInvoiceIDFromEvent(): string {
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

  private function extractName(string $name): array {
    $name = trim($name);
    $last_name = (strpos($name, ' ') === FALSE) ? NULL : preg_replace('#.*\s([\w-]*)$#', '$1', $name);
    $first_name = trim(preg_replace('#' . preg_quote($last_name ?? '','#') . '#', '', $name));
    if (empty($first_name)) {
      $first_name = NULL;
    }
    return [$first_name, $last_name];
  }

  /**
   * Get the contact ID from the event using the shopperEmail / shopperName
   * Match to existing contact or create new contact with the details provided
   *
   * Sets $this->contactID
   */
  public function identifyContactFromEvent() {
    $event = $this->eventData;
    $email = $event['additionalData']['shopperEmail'] ?? NULL;
    //  [shopperName] => [first name=Ivan, infix=null, last name=Velasquez, gender=null]
    $name = $this->extractName($event['additionalData']['shopperName'] ?? '');
    $firstName = $name[0] ?? NULL;
    $lastName = $name[1] ?? NULL;

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
   *
   * This creates a contribution in CiviCRM if it does not already exist
   * and, where possible, it updates the card details for an existing payment token.
   *
   * @return string Detailed log message
   */
  public function processAuthorisationEvent() :string {

    $trxnID = $this->getPaymentTrxnIDFromEvent();
    // The authorization for the card was not successful so we ignore it.
    if (empty($this->eventData['success'])) {
      throw new WebhookEventIgnoredException('Ignoring Authorization not successful for merchant Reference: ' . $trxnID);
    }

    $invoiceID = $this->getContributionInvoiceIDFromEvent();

    // The authorization for the card was successful so we create a contribution if we don't already have one.
    $contribution = Contribution::get(FALSE)
      ->addSelect('id', 'contact_id', 'contribution_recur_id', 'invoice_id', 'trxn_id')
      ->addWhere('invoice_id', '=', $invoiceID)
      ->addWhere('is_test', 'IN', [0, 1])
      ->execute()
      ->first();
    if (empty($contribution)) {
      switch ($this->extraConfig['unmatchedContributionBehaviour'] ?? 'create') {
        case 'retry':
          throw new WebhookEventRetryException('Cannot find contribution with invoice_id=' . $invoiceID);

        case 'create':
          $this->identifyContactFromEvent();
          $contribution = $this->createNewContributionFromAuthorizedPayment();
          $message = "OK. Created new contribution";
          break;

        default:
          throw new \InvalidArgumentException("Invalid value '{$this->extraConfig['unmatchedContributionBehaviour']}' for unmatchedContributionBehaviour");
      }
    }
    else {
      // Found contribution, store the contact ID
      $this->contactID = $contribution['contact_id'];
      $message = "OK. Matched existing contribution";
    }
    $message .= " {$contribution['id']}, invoice_id {$contribution['invoice_id']}, trxn_id {$contribution['trxn_id']}.";

    // Now we know that we have the contact and the contribution, see if we need to update the payment method details.
    if ( !empty($contribution['contribution_recur_id'])
      && !empty($this->eventData['additionalData']['cardSummary'])
      && !empty($this->eventData['additionalData']['expiryDate'])
      && !empty($this->eventData['additionalData']['paymentMethod'])
      && !empty($contribution)
      ) {
      // This belongs to a recur, and we have card details.
      $result = \Civi\Api4\ContributionRecur::get()
      ->addSelect('payment_token_id.id', 'payment_token_id.expiry_date', 'payment_token_id.masked_account_number')
      ->addWhere('id', '=', $contribution['contribution_recur_id'])
      ->execute()->first();
      if (!empty($result['payment_token_id.id'])) {
        // We have the payment token for this recur.
        $updates = [];
        $paymentMethod = ucfirst($this->eventData['additionalData']['paymentMethod']);
        $expectedCardDetails = "{$paymentMethod}: {$this->eventData['additionalData']['cardSummary']}";
        if ($expectedCardDetails !== $result['payment_token_id.masked_account_number']) {
          $updates['masked_account_number'] = $expectedCardDetails;
        }

        if (preg_match('@^(\d\d)/(\d{4})$@', $this->eventData['additionalData']['expiryDate'], $matches)) {
          // cards expire at the end of this month.
          $expectedExpiry = date('Y-m-d H:i:s', strtotime("{$matches[2]}-{$matches[1]}-01 + 1 month - 1 minute"));
          if ($expectedExpiry !== $result['payment_token_id.expiry_date']) {
            $updates['expiry_date'] = $expectedExpiry;
          }
        }
        if ($updates) {
          \Civi\Api4\PaymentToken::update(FALSE)
          ->addWhere('id', '=', $result['payment_token_id.id'])
          ->setValues($updates)
          ->execute();
          $message .= " Updated payment token details " . json_encode($updates) . " from original: " . json_encode($result);
        }
      }
    }

    if (!empty($contribution)) {
      $updates = [];
      // back-date contribution to event date if it's currently set to a later date
      if (\CRM_Utils_Date::processDate($contribution['receive_date']) > \CRM_Utils_Date::processDate($this->eventData['eventDate'])) {
        $updates['receive_date'] = \CRM_Utils_Date::processDate($this->eventData['eventDate']);
      }

      // add pspReference if not present
      if (empty($contribution['trxn_id'])) {
        $updates['trxn_id'] = $this->eventData['pspReference'];
      }

      if (!empty($updates)) {
        $message .= " Updated contribution details " . json_encode($updates) . " from original: " . json_encode($contribution);
      }
    }

    return $message;
  }

  /**
   * Create a completed contribution for this authorized payment.
   *
   * @return array for the contribution created containing
   *    id, contribution_recur_id (null), invoice_id, trxn_id
   */
  public function createNewContributionFromAuthorizedPayment(): array {

    $amount = $this->getAmountFromEvent();
    $date = $this->eventData['eventDate'] ?? date('Y-m-d H:i:s');
    $orderCreateParams = [
      'receive_date' => $date,
      'total_amount' => $amount,
      'currency' => $this->getCurrencyFromEvent(),
      'invoice_id' => $this->getContributionInvoiceIDFromEvent(),
      'contact_id' => $this->contactID,
      // @fixme: This should probably be configurable
      'financial_type_id' => 'Donation',
      'payment_instrument_id' => 'Credit Card',
      "line_items"  => [
        // Main (only) group
        [
          "params" => [],
          "line_item" => [
            // Line items belonging to the group
            [
              "qty" => 1,
              "unit_price" => $amount,
              "line_total" => $amount,
              "price_field_id" => 1,
              "price_field_value_id" => 1,
            ]
          ]
        ]
      ]
    ];
    $contribution = civicrm_api3('Order', 'create', $orderCreateParams);

    // Now add the payment
    $paymentCreateParams = [
      'contribution_id'                   => $contribution['id'],
      'total_amount'                      => $amount,
      'trxn_id'                           => $this->getPaymentTrxnIDFromEvent(),
      'trxn_date'                         => $date,
      'is_send_contribution_notification' => FALSE, /* @todo? */
    ];
    civicrm_api3('Payment', 'create', $paymentCreateParams);

    // Reload the contribution.
    $contribution = Contribution::get(FALSE)
    ->addSelect('id', 'contact_id', 'contribution_recur_id', 'invoice_id', 'trxn_id')
      ->addWhere('id', '=', $contribution['id'])
      ->addWhere('is_test', 'IN', [0, 1])
      ->execute()
      ->first();

    return $contribution;
  }
}

