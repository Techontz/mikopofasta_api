<?php

namespace App\Http\Requests\Api\Messages;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Messages → Create Group (heads only; members must be within the head's allowed contacts).
 */
class GroupRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', Rule::exists('employees', 'id')->where('company_id', $this->user()->company_id)],
            'body' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
