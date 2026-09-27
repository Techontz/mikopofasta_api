<?php

namespace App\Http\Requests\Api\Crm;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CRM → Send SMS to one customer (same field as the live customer profile "Send SMS").
 */
class SmsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('crm.use');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('company_id', $this->user()->company_id)],
            'message' => ['required', 'string', 'max:480'],
            'follow_up_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
