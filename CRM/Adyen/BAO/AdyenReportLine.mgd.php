<?php
return [
  [
    'module'  => 'adyen',
    'name'    => 'adyen_report_line_status',
    'entity'  => 'OptionGroup',
    'cleanup' => 'never',
    'params'  => [
      'version'   => 3,
      'name'      => 'adyen_report_line_status',
      'title'     => 'Adyen Report Line Status',
      'data_type' => 'Integer',
      'is_active' => 1,
    ],
  ],
  [
    'module'  => 'adyen',
    'name'    => 'adyen_report_line_status_pending',
    'entity'  => 'OptionValue',
    'cleanup' => 'never',
    'params'  => [
      'version'         => 3,
      'option_group_id' => 'adyen_report_line_status',
      'value'           => 1,
      'name'            => 'pending',
      'label'           => 'Pending',
      'is_active'       => 1,
    ],
  ],
  [
    'module'  => 'adyen',
    'name'    => 'adyen_report_line_status_in_progress',
    'entity'  => 'OptionValue',
    'cleanup' => 'never',
    'params'  => [
      'version'         => 3,
      'option_group_id' => 'adyen_report_line_status',
      'value'           => 2,
      'name'            => 'in_progress',
      'label'           => 'In Progress',
      'is_active'       => 1,
    ],
  ],
  [
    'module'  => 'adyen',
    'name'    => 'adyen_report_line_status_completed',
    'entity'  => 'OptionValue',
    'cleanup' => 'never',
    'params'  => [
      'version'         => 3,
      'option_group_id' => 'adyen_report_line_status',
      'value'           => 3,
      'name'            => 'completed',
      'label'           => 'Completed',
      'is_active'       => 1,
    ],
  ],
];
