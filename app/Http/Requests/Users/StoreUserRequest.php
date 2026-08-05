<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Domain\Auth\Enums\RoleName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's CreateUserSchema
 * (features/admin/users/users-actions.ts).
 *
 * Field names are camelCase because that is what the frontend form submits —
 * see the casing note on App\Support\ApiResponse.
 */
final class StoreUserRequest extends FormRequest
{
    /**
     * Authorization is handled by UserPolicy via the controller's
     * authorizeResource / explicit authorize() call.
     */
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
            'name' => ['required', 'string', 'min:2', 'max:150'],

            // Unique across soft-deleted rows too: the column carries a plain
            // UNIQUE index, so a "deleted" user still occupies its phone
            // number. Silently colliding would fail at the database instead.
            'phone' => ['required', 'string', 'min:9', 'max:20', Rule::unique('users', 'phone')],

            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'string', Rule::in(RoleName::assignable())],

            // Existence is validated now that the organization tables exist
            // (Phase 3). These columns also carry real FK constraints, so an
            // unchecked id would surface as a 500 rather than a field error.
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
            'password.min' => 'Password must be at least 6 characters',
        ];
    }
}
