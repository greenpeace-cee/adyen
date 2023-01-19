<?php

namespace Civi\Api4\Action\AdyenNotification;

use Civi\Api4\AdyenNotification;
use Civi\Api4\Generic\Result;

/**
 * Process all pending notifications
 *
 */
class ProcessPending extends \Civi\Api4\Generic\AbstractAction {

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \API_Exception
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function _run(Result $result) {
    $pendingStatus = \CRM_Core_PseudoConstant::getKey(
      'CRM_Adyen_BAO_AdyenNotification',
      'status_id',
      'pending'
    );
    $lock = \Civi::lockManager()->acquire('worker.adyen.NotificationProcessor');
    if (!$lock->isAcquired()) {
      throw new \API_Exception('Process is already running.');
    }
    $adyenNotifications = AdyenNotification::get()
      ->addWhere('status_id', '=', $pendingStatus)
      ->addOrderBy('processing_order', 'ASC')
      ->addOrderBy('event_date', 'ASC')
      ->addOrderBy('id', 'ASC')
      ->execute();
    foreach ($adyenNotifications as $adyenNotification) {
      $notification = \Civi\Adyen\Notification\Factory::createFromEntity($adyenNotification);
      $notification->process();
      // refresh and add to results
      $result[] = AdyenNotification::get()
        ->addWhere('id', '=', $adyenNotification['id'])
        ->execute()
        ->first();
    }

  }

}
