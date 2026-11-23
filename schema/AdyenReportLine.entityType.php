<?php
use CRM_Adyen_ExtensionUtil as E;

return [
  'name' => 'AdyenReportLine',
  'table' => 'civicrm_adyen_report_line',
  'class' => 'CRM_Adyen_DAO_AdyenReportLine',
  'getInfo' => fn() => [
    'title' => E::ts('Adyen Report Line'),
    'title_plural' => E::ts('Adyen Report Lines'),
    'description' => E::ts('List of lines in an Adyen report'),
    'log' => TRUE,
  ],
  'getIndices' => fn() => [
    'UI_adyen_notification_line_number' => [
      'fields' => [
        'adyen_notification_id' => TRUE,
        'line_number' => TRUE,
      ],
      'unique' => TRUE,
    ],
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique AdyenReportLine ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'adyen_notification_id' => [
      'title' => E::ts('Adyen Notification ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('FK to AdyenNotification'),
      'entity_reference' => [
        'entity' => 'AdyenNotification',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'line_number' => [
      'title' => E::ts('Line Number'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Line number in report file'),
    ],
    'line_date' => [
      'title' => E::ts('Line Date'),
      'sql_type' => 'datetime',
      'input_type' => 'Select Date',
      'description' => E::ts('Date of the line'),
    ],
    'content' => [
      'title' => E::ts('Content'),
      'sql_type' => 'longtext',
      'input_type' => 'TextArea',
      'required' => TRUE,
      'description' => E::ts('Adyen Report Line (JSON)'),
      'serialize' => constant('CRM_Core_DAO::SERIALIZE_JSON'),
      'input_attrs' => [
        'rows' => 4,
        'cols' => 60,
      ],
    ],
    'status_id' => [
      'title' => E::ts('Report Line Status'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'required' => TRUE,
      'description' => E::ts('ID of report line status'),
      'default' => 1,
      'pseudoconstant' => [
        'option_group_name' => 'adyen_report_line_status',
      ],
    ],
  ],
];
