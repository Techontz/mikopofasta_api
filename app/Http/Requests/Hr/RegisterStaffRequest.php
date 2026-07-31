<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\Enums\StaffPaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * `POST /staff` — §15.5. Creates a user and a staff profile together (§11), so
 * the payload validates both halves.
 */
final class RegisterStaffRequest extends FormRequest
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
            // The user half — the same rules POST /users applies, because the
            // account being created is an ordinary one.
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')->whereNull('deleted_at')],
            'email' => ['sometimes', 'nullable', 'email', 'max:150', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'password' => ['required', 'string', Password::min(8)],
            'role' => ['required', 'string', Rule::in(RoleName::values())],
            'branchId' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'zoneId' => ['sometimes', 'nullable', 'integer', Rule::exists('zones', 'id')->whereNull('deleted_at')],
            'regionId' => ['sometimes', 'nullable', 'integer', Rule::exists('regions', 'id')],

            // The employment half. Salary is a decimal string for the same
            // reason every other money field is — see App\Support\Money.
            'baseSalary' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'commissionEligible' => ['sometimes', 'boolean'],
            'paymentMethod' => ['sometimes', 'string', Rule::in(StaffPaymentMethod::values())],
            'hiredAt' => ['required', 'date', 'before_or_equal:today'],

            'bankName' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bankAccountNumber' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
