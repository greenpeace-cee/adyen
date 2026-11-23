<?php

/**
 * DAOs provide an OOP-style facade for reading and writing database records.
 *
 * DAOs are a primary source for metadata in older versions of CiviCRM (<5.74)
 * and are required for some subsystems (such as APIv3).
 *
 * This stub provides compatibility. It is not intended to be modified in a
 * substantive way. Property annotations may be added, but are not required.
 * @property string $id
 * @property string $event_code_id
 * @property string $event_date
 * @property string $psp_reference
 * @property string $merchant_account
 * @property string $processing_order
 * @property string $status_id
 * @property string $notification
 */
class CRM_Adyen_DAO_AdyenNotification extends CRM_Adyen_DAO_Base {

  /**
   * Required by older versions of CiviCRM (<5.74).
   * @var string
   */
  public static $_tableName = 'civicrm_adyen_notification';

}
