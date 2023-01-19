<?php

return [
  'adyen_merchant_config' => [
    'name'       => 'adyen_merchant_config',
    'type'       => 'Array',
    'html_type'  => 'text',
    'default'    => [
      'SampleMerchantAccount' => [
        'reportUserName'      => 'report@Company.SampleMerchantAccount',
        'reportUserPassword'  => 'secret',
        'hmacKeys'            => [
          '123',
          '456',
        ],
      ],
    ],
    'add'        => '1.0',
    'title'      => ts('Adyen Merchant Account Configuration'),
    'is_domain'  => 1,
    'is_contact' => 0,
  ],
];
