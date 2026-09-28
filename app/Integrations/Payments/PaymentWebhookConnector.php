<?php

namespace App\Integrations\Payments;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Incoming mobile-money / bank payment webhook (swappable connector selected by config('integrations.payments.driver')).
 */
interface PaymentWebhookConnector
{
    /**
     * Whether the request carries a valid provider signature.
     */
    public function verify(Request $request): bool;

    /**
     * Convert the provider payload into a normalised notification.
     *
     * @throws ValidationException
     */
    public function parse(Request $request): PaymentNotification;
}
