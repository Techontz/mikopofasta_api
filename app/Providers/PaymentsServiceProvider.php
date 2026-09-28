<?php

namespace App\Providers;

use App\Integrations\Payments\HmacPaymentWebhookConnector;
use App\Integrations\Payments\PaymentWebhookConnector;
use App\Integrations\Payments\TestPaymentWebhookConnector;
use Illuminate\Support\ServiceProvider;

/**
 * Repayments module: binds the payment webhook connector by configured driver.
 */
class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(
            PaymentWebhookConnector::class,
            fn (): PaymentWebhookConnector => config('integrations.payments.driver', 'test') === 'test' ? new TestPaymentWebhookConnector : new HmacPaymentWebhookConnector,
        );
    }
}
