<?php

namespace App\Http\Requests\Api\Crm;

use App\Models\CrmInteraction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CRM → Record Call (incoming call received from a customer or outgoing call made by staff).
 */
class CallRequest extends FormRequest
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
            'direction' => ['required', Rule::in(array_keys(CrmInteraction::DIRECTIONS))],
            'outcome' => ['required', Rule::in(array_keys(CrmInteraction::OUTCOMES))],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'follow_up_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
