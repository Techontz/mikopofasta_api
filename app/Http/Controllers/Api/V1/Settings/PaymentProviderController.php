<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Settings\PaymentProviderRequest;
use App\Models\PaymentProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings → Payment Channels: the few banks and mobile networks the company receives customer payments through. Only Super
 * Admin and Admin (settings.manage) maintain the list; Finance and tellers read the active ones to record a payment.
 */
class PaymentProviderController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        return response()->json(['data' => $this->providers()->get()->map(fn (PaymentProvider $provider): array => $this->row($provider))]);
    }

    /**
     * Active providers of one channel as select options (value = label = name).
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorizeAny('settings.manage', 'payments.suspense', 'payments.cash', 'loans.recover');
        $channel = $request->validate(['channel' => ['required', 'in:'.implode(',', PaymentProvider::CHANNELS)]])['channel'];

        return response()->json(['data' => $this->providers()->active()->where('channel', $channel)->get()
            ->map(fn (PaymentProvider $provider): array => ['value' => $provider->name, 'label' => $provider->name])]);
    }

    public function store(PaymentProviderRequest $request): JsonResponse
    {
        $provider = PaymentProvider::create($request->validated() + ['company_id' => $this->currentEmployee()->company_id]);

        return $this->message('Payment channel Registered successfully', 201, ['data' => $this->row($provider)]);
    }

    public function update(PaymentProviderRequest $request, PaymentProvider $paymentProvider): JsonResponse
    {
        $this->assertOwned($paymentProvider);
        $paymentProvider->update($request->validated());

        return $this->message('Payment channel Updated successfully', 200, ['data' => $this->row($paymentProvider)]);
    }

    /**
     * Payments keep the provider name they were recorded with, so removing a provider never changes history.
     */
    public function destroy(PaymentProvider $paymentProvider): JsonResponse
    {
        $this->authorizeAny('settings.manage');
        $this->assertOwned($paymentProvider);
        $paymentProvider->delete();

        return $this->message('Payment channel Deleted successfully');
    }

    private function assertOwned(PaymentProvider $provider): void
    {
        abort_unless((int) $provider->company_id === (int) $this->currentEmployee()->company_id, 404);
    }

    /**
     * @return Builder<PaymentProvider>
     */
    private function providers()
    {
        return PaymentProvider::where('company_id', $this->currentEmployee()->company_id)->orderBy('channel')->orderBy('name');
    }

    /**
     * @return array{id: int, channel: string, name: string, is_active: bool}
     */
    private function row(PaymentProvider $provider): array
    {
        return ['id' => $provider->id, 'channel' => $provider->channel, 'name' => $provider->name, 'is_active' => (bool) $provider->is_active];
    }
}
