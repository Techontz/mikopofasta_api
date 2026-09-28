<?php

namespace App\Http\Requests\Api\Crm;

use App\Models\CrmTicket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CRM → update a customer report: status, priority, assignee and resolution.
 */
class UpdateTicketRequest extends FormRequest
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
            'status' => ['required', Rule::in(array_keys(CrmTicket::STATUSES))],
            'priority' => ['required', Rule::in(array_keys(CrmTicket::PRIORITIES))],
            'assigned_to' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('company_id', $this->user()->company_id)],
            'resolution' => ['nullable', 'required_if:status,resolved,closed', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['resolution.required_if' => 'The resolution is required when the report is resolved or closed.'];
    }
}
