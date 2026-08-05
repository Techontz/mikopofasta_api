<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Domain\Auth\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's UpdateUserSchema, which is CreateUserSchema with
 * `password` omitted.
 */
final class UpdateUserRequest extends FormRequest
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
        $user = $this->route('user');
        $userId = $user instanceof User ? $user->getKey() : null;

        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'phone' => ['required', 'string', 'min:9', 'max:20', Rule::unique('users', 'phone')->ignore($userId)],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($userId)],
            'role' => ['required', 'string', Rule::in(RoleName::assignable())],

            // Existence validated as of Phase 3 — see StoreUserRequest.
            'branchId' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'zoneId' => ['nullable', 'integer', Rule::exists('zones', 'id')->whereNull('deleted_at')],
            'regionId' => ['nullable', 'integer', Rule::exists('regions', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.unique' => 'A user with this phone number already exists.',
        ];
    }
}
