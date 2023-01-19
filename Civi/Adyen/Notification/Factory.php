<?php

namespace Civi\Adyen\Notification;

/**
 * Class Factory
 *
 * Create notification object(s) based on different data representations
 *
 * @package Civi\Adyen\Notification
 */
class Factory {

  /**
   * Create an array of notification objects based on a submitted Adyen payload
   *
   * @param array $payload
   *
   * @return \Civi\Adyen\Notification\Report[]
   * @throws \Exception
   */
  public static function createFromPayload(array $payload) {
    $notifications = [];
    if (empty($payload['notificationItems']) || !is_array($payload['notificationItems'])) {
      throw new \Exception('Invalid notification payload: notificationItems is empty');
    }

    foreach ($payload['notificationItems'] as $item) {
      if (empty($item['NotificationRequestItem'])) {
        throw new \Exception('Invalid notification payload: is empty');
      }
      $item = $item['NotificationRequestItem'];
      switch ($item['eventCode']) {
        case 'REPORT_AVAILABLE':
          $notifications[] = new Report($item);
          break;
      }
    }
    return $notifications;
  }

  /**
   * Create a single notification object based on an AdyenNotification entity
   *
   * @param array $notification
   *
   * @return \Civi\Adyen\Notification\Report
   * @throws \Exception
   */
  public static function createFromEntity(array $notification) {
    switch ($notification['event_code_id']) {
      case \CRM_Core_PseudoConstant::getKey(
        'CRM_Adyen_BAO_AdyenNotification',
        'event_code_id',
        'report_available'
      ):
        $notificationInstance = new Report($notification['notification']);
        break;

      default:
        throw new Exception("Unknown value '{$notification['event_code_id']}' for event_code_id");
    }
    $notificationInstance->setId($notification['id']);
    return $notificationInstance;
  }

}
