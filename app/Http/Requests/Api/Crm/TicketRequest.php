<?php

namespace App\Http\Requests\Api\Crm;

use App\Models\CrmTicket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CRM → Customer Report / Complaint.
 */
class TicketRequest extends FormRequest
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
        $companyId = $this->user()->company_id;

        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'category' => ['required', Rule::in(array_keys(CrmTicket::CATEGORIES))],
            'channel' => ['required', Rule::in(array_keys(CrmTicket::CHANNELS))],
            'priority' => ['required', Rule::in(array_keys(CrmTicket::PRIORITIES))],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('company_id', $companyId)],
        ];
    }
}
