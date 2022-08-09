<?php
namespace Civi\Adyen;

class WebhookException extends \Exception {
  public string $publicSafeMessage;

  public function __construct(string $message, ?string $publicSafeMessage = NULL) {
    parent::__construct($message);
    // Assume message is public-safe unless another has been specified.
    $this->publicSafeMessage = $publicSafeMessage ?? $message;
  } }
