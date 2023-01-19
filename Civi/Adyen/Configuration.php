<?php

namespace Civi\Adyen;

class Configuration {

  public static function getMerchantConfiguration($merchantAccount, $attribute = NULL) {
    $config = \Civi::settings()->get('adyen_merchant_config');
    if (empty($config[$merchantAccount])) {
      throw new \Exception("No configuration found for merchant account '{$merchantAccount}'");
    }

    if (is_null($attribute)) {
      return $config[$merchantAccount];
    }
    else {
      if (empty($config[$merchantAccount][$attribute])) {
        throw new \Exception("Unknown attribute '{$attribute}' for merchant account '{$merchantAccount}'");
      }
      return $config[$merchantAccount][$attribute];
    }
  }

}
