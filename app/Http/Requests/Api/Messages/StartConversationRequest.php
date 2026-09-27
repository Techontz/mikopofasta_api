<?php

namespace App\Http\Requests\Api\Messages;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Messages → New Message to one employee.
 */
class StartConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('messages.use');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $this->user()->company_id)],
            'body' => ['required', 'string', 'max:4000'],
        ];
    }
}
