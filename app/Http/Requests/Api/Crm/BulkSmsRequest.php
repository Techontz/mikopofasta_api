<?php

namespace App\Http\Requests\Api\Crm;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CRM → Bulk SMS to the customers of a branch filtered by customer status.
 */
class BulkSmsRequest extends FormRequest
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
            'branch_id' => ['required', 'string'],
            'customer_status' => ['required', Rule::in(['all', 'pending', 'open', 'out', 'close'])],
            'message' => ['required', 'string', 'max:480'],
        ];
    }
}
