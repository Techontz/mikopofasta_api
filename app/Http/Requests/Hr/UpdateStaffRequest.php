<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use App\Domain\Hr\Enums\EmploymentStatus;
use App\Domain\Hr\Enums\StaffPaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /staff/{staffProfile}` — amending employment terms.
 *
 * Only the employment half is editable here. Changing a user's name, role or
 * branch goes through the user endpoints, which already own the token
 * revocation a role change requires — doing it in two places would let a
 * demoted employee keep a live token.
 */
final class UpdateStaffRequest extends FormRequest
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
            'baseSalary' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0'],
            'commissionEligible' => ['sometimes', 'boolean'],
            'paymentMethod' => ['sometimes', 'string', Rule::in(StaffPaymentMethod::values())],
            'employmentStatus' => ['sometimes', 'string', Rule::in(EmploymentStatus::values())],
            'bankName' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bankAccountNumber' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
