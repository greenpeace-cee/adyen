<?php 
namespace Civi\Adyen;

/**
 * Thrown when an event is not important to us, but we got sent it anyway.
 *
 * Ignoring these is normal behaviour.
 */
class WebhookEventIgnoredException extends \Exception {}
