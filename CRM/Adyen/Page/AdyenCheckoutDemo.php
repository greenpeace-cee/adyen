<?php
use CRM_Adyen_ExtensionUtil as E;

class CRM_Adyen_Page_AdyenCheckoutDemo extends CRM_Core_Page {

  public function run() {
    // Example: Set the page-title dynamically; alternatively, declare a static title in xml/Menu/*.xml
    // CRM_Utils_System::setTitle(E::ts('AdyenCheckoutDemo'));
    //
    // // Example: Assign a variable for use in a template
    // $this->assign('currentTime', date('Y-m-d H:i:s'));


    // Find the first test Adyen payment processor.
    $paymentProcessor = \Civi\Api4\PaymentProcessor::get()
    ->addWhere('payment_processor_type_id:name', '=', 'Adyen')
    ->addWhere('is_test', '=', TRUE)
    ->setLimit(25)
    ->execute()->first();

    if (!$paymentProcessor) {
      $this->assign('error', "No test Adyen payment processor configured");
    }
    else {
      /** @var CRM_Core_Payment_Adyen */
      $ppObject = \Civi\Payment\System::singleton()->getByProcessor($paymentProcessor);

      $adyenSessionID = $_GET['sessionId'] ?? '';
      try {
        if ($adyenSessionID) {
          // We've been called as a callback.
          $params = [
            'id' => $adyenSessionID,
            'merchantAccount' => $ppObject->getMerchantAccount(),
            'clientKey' => $ppObject->getClientKey(),
            'redirectResult' => $_GET['redirectResult'] ?? '',
          ];
          $this->assign('adyenSession', json_encode($params));
        }
        else {
          // Look up current contact's primary email
          $emailAddress = \Civi\Api4\Email::get(FALSE)
          ->addWhere('contact_id', '=', CRM_Core_Session::singleton()->getLoggedInContactID())
          ->addWhere('is_primary', '=', 1)
          ->execute()->single()['email'];
          ;
          $this->assign('email', $emailAddress);

          $service = new \Adyen\Service\Checkout($ppObject->client);
          $params = [
            'amount' => [
              'currency' => "EUR",
              'value' => 101
            ],
            'countryCode' => 'GB',
            'merchantAccount' => $ppObject->getMerchantAccount(),
            'reference' => 'CiviCRM-test-' . date('Ymdhis'),
            'returnUrl' => \Civi::paths()->getUrl('civicrm/adyen-checkout-demo', 'absolute'),
            'shopperEmail' => $emailAddress,
          ];
          $sessionResult = $service->sessions($params);
          $this->assign('adyenSession', json_encode($sessionResult +
            ['clientKey' => $ppObject->getClientKey()]));
        }

      }
      catch (\Exception $e) {
        $this->assign('error', htmlspecialchars(get_class($e) . ": " . $e->getMessage())
          . '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>'
          . '<pre>' . htmlspecialchars(json_encode($params)) . '</pre>'
        );
      }
    }

    parent::run();
  }

}
