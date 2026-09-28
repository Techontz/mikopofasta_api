<?php

namespace App\Integrations\Payments;

/**
 * Test driver: same signature check as the real driver, but falls back to the fixed secret
 * "test-webhook-secret" when none is configured so local simulations are deterministic.
 */
class TestPaymentWebhookConnector extends HmacPaymentWebhookConnector
{
    public const DEFAULT_SECRET = 'test-webhook-secret';

    protected function secret(): ?string
    {
        return parent::secret() ?? self::DEFAULT_SECRET;
    }
}
