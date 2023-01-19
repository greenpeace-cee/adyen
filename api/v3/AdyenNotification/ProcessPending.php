<?php

use Civi\Api4\AdyenNotification;

/**
 * AdyenNotification.process_pending API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_adyen_notification_process_pending_spec(&$spec) {

}

/**
 * AdyenNotification.process_pending API
 *
 * Wrapper for the AdyenNotification.processPending v4 API. This only exists
 * because (as of Civi 5.19) it's not possible to trigger v4 API calls via
 * scheduled jobs or drush.
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @see civicrm_api3_create_success
 *
 * @throws API_Exception
 */
function civicrm_api3_adyen_notification_process_pending($params) {
  return civicrm_api3_create_success((array) AdyenNotification::processPending()->execute(), $params, 'AdyenNotification', 'process_pending');
}
