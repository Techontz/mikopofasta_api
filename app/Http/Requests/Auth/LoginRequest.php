<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mirrors the frontend's loginSchema in lib/auth/actions.ts:
 * phone (min 9) + password (required).
 */
final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'password' => ['required', 'string'],

            // Optional label for the issued token, so a user can tell their
            // sessions apart. Defaults to "mikopofasta-web".
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.min' => 'Enter a valid phone number',
            'password.required' => 'Password is required',
        ];
    }
}
