<?php

namespace Civi\Adyen;

/**
 * Thrown when a webhook event should be retried later e.g. because not all
 * relevant related data has arrived yet
 */
class WebhookEventRetryException extends \Exception {}
