<?php

declare(strict_types=1);

namespace App\Http\Requests\Treasury;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's ShareholderInputSchema and the legacy form's five
 * fields. Used for both create and edit — on edit the route model is excluded
 * from the two uniqueness checks.
 */
final class StoreShareholderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('shareholder')?->id;

        return [
            'fullName' => ['required', 'string', 'min:3', 'max:150'],
            'phone' => [
                'required', 'string', 'max:20',
                Rule::unique('shareholders', 'phone')->ignore($id)->whereNull('deleted_at'),
            ],
            'email' => [
                'required', 'email', 'max:150',
                Rule::unique('shareholders', 'email')->ignore($id)->whereNull('deleted_at'),
            ],
            'gender' => ['required', Rule::in(['male', 'female'])],
            // A shareholder is a real person with a real birthday: not today,
            // and not in the future.
            'dateOfBirth' => ['required', 'date', 'before:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.unique' => 'A shareholder with this phone number already exists.',
            'email.unique' => 'A shareholder with this email already exists.',
            'dateOfBirth.before' => 'Date of birth must be in the past.',
        ];
    }
}
