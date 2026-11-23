<?php
use CRM_Adyen_ExtensionUtil as E;

return [
  'name' => 'AdyenNotification',
  'table' => 'civicrm_adyen_notification',
  'class' => 'CRM_Adyen_DAO_AdyenNotification',
  'getInfo' => fn() => [
    'title' => E::ts('Adyen Notification'),
    'title_plural' => E::ts('Adyen Notifications'),
    'description' => E::ts('List of Adyen notifications received via webhooks'),
    'log' => TRUE,
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('AdyenNotification ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique AdyenNotification ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'event_code_id' => [
      'title' => E::ts('Event Code'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'required' => TRUE,
      'description' => E::ts('ID of event code'),
      'pseudoconstant' => [
        'option_group_name' => 'adyen_notification_event_code',
      ],
    ],
    'event_date' => [
      'title' => E::ts('Event Date'),
      'sql_type' => 'datetime',
      'input_type' => 'Select Date',
      'required' => TRUE,
      'description' => E::ts('Date of the event'),
    ],
    'psp_reference' => [
      'title' => E::ts('PSP Reference'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('PSP Reference'),
    ],
    'merchant_account' => [
      'title' => E::ts('Merchant Account'),
      'sql_type' => 'varchar(80)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Merchant Account'),
    ],
    'processing_order' => [
      'title' => E::ts('Processing Order'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'description' => E::ts('Processing Order'),
    ],
    'status_id' => [
      'title' => E::ts('Notification Status'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'required' => TRUE,
      'description' => E::ts('ID of notification status'),
      'default' => 1,
      'pseudoconstant' => [
        'option_group_name' => 'adyen_notification_status',
      ],
    ],
    'notification' => [
      'title' => E::ts('Notification'),
      'sql_type' => 'longtext',
      'input_type' => 'TextArea',
      'required' => TRUE,
      'description' => E::ts('Adyen NotificationRequestItem (JSON)'),
      'serialize' => constant('CRM_Core_DAO::SERIALIZE_JSON'),
      'input_attrs' => [
        'rows' => 4,
        'cols' => 60,
      ],
    ],
  ],
];
