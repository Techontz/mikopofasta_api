<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StaffProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `StaffProfileSchema` in the frontend's types/staff.ts.
 * Money goes out as a decimal string — see LoanResource for why.
 *
 * @mixin StaffProfile
 */
final class StaffProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'userId' => (string) $this->user_id,
            'employeeNumber' => $this->employee_number,
            'branchId' => self::id($this->branch_id),
            'zoneId' => self::id($this->zone_id),
            'baseSalary' => $this->base_salary,
            'commissionEligible' => $this->commission_eligible,
            'paymentMethod' => $this->payment_method->value,
            'employmentStatus' => $this->employment_status->value,
            'hiredAt' => $this->hired_at->toDateString(),
            'deletedAt' => $this->deleted_at?->toIso8601String(),

            // What the staff table renders alongside each row. The frontend
            // resolves these from its own mock stores; over HTTP they have to
            // travel with the record or every row costs another request.
            'name' => $this->whenLoaded('user', fn (): ?string => $this->user?->name),
            'role' => $this->whenLoaded('user', fn (): ?string => $this->user?->role?->name),
            'branchName' => $this->whenLoaded('branch', fn (): ?string => $this->branch?->name),

            'bankDetails' => $this->whenLoaded('bankDetail', fn (): ?array => $this->bankDetail === null ? null : [
                'id' => (string) $this->bankDetail->id,
                'staffProfileId' => (string) $this->bankDetail->staff_profile_id,
                'bankName' => $this->bankDetail->bank_name,
                'accountNumber' => $this->bankDetail->account_number,
            ]),
        ];
    }

    private static function id(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
