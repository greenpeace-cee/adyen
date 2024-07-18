<?php

require_once 'adyen.civix.php';
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
  require_once $autoload;
}

// phpcs:disable
use CRM_Adyen_ExtensionUtil as E;
// phpcs:enable

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function adyen_civicrm_config(&$config) {
  _adyen_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function adyen_civicrm_install() {
  _adyen_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function adyen_civicrm_enable() {
  _adyen_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_check().
 *
 * @throws \CiviCRM_API3_Exception
 */
function adyen_civicrm_check(&$messages) {
  $checks = new CRM_Adyen_Check($messages);
  $messages = $checks->checkRequirements();
}
