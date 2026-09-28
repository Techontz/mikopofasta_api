<?php

namespace App\Http\Requests\Api\Settings;

use App\Models\PaymentProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Settings → Payment Channels: a bank or network name the company receives payments through, unique per company and channel.
 */
class PaymentProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.manage');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $provider = $this->route('payment_provider');

        return [
            'channel' => ['required', Rule::in(PaymentProvider::CHANNELS)],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('payment_providers', 'name')->where('company_id', $this->user()->company_id)->where('channel', $this->input('channel'))->ignore($provider?->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
