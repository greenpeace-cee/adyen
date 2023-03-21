<?php
namespace Civi\Api4\Action\PaymentToken;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Contribution;
use Civi\Api4\Generic\Result;
use Civi\Core\Event\GenericHookEvent;
use CRM_Core_Exception;
use CRM_Core_Lock;
use CRM_Core_Payment_Adyen;
use CRM_Core_Transaction;
use CRM_Contribute_BAO_ContributionRecur;
use CRM_Contribute_BAO_Contribution;
use function Civi\Api4\Action\ContributionRecur\json_encode;

class GetAdyenPaymentMethod extends \Civi\Api4\Generic\AbstractAction
{

  /**
   * Adyen Payment Processor
   *
   * @required
   * @var int
   * @fkEntity PaymentProcessor
   */
  protected int $paymentProcessorId;

  /**
   * Adyen shopper reference
   *
   * @required
   * @var string
   */
  protected string $shopperReference;

  /**
   * Do stuff
   */
  public function _run(Result $result) {
    $paymentProcessor = \Civi\Payment\System::singleton()->getById($this->paymentProcessorId);
    if (!$paymentProcessor instanceof CRM_Core_Payment_Adyen) {
      throw new \API_Exception('Supplied Payment Processor must be of type CRM_Core_Payment_Adyen');
    }
    $paymentMethods = $paymentProcessor->getStoredPaymentMethod($this->shopperReference);
    foreach ($paymentMethods['storedPaymentMethods'] ?? [] as $key => $paymentMethod) {
      $result[$key] = $paymentMethod;
    }
  }

}
