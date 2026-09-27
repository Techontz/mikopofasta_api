<?php

namespace App\Http\Requests\Api\Bank;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bank → Register Account (live field ac_name) with an optional opening balance on create.
 */
class BankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bank.manage');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ac_name' => ['required', 'string', 'max:255'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
