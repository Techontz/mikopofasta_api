<?php

namespace App\Http\Resources\Api\V1\Hrm;

use App\Enums\SalaryType;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Employee
 */
class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'username' => $this->username,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender,
            'position' => $this->position,
            'status' => $this->status,
            'photo_url' => $this->photo_url,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'role_id' => $this->role_id,
            'role' => $this->whenLoaded('role', fn () => $this->role ? ['id' => $this->role->id, 'key' => $this->role->key, 'name' => $this->role->name, 'scope' => $this->role->scope] : null),
            'zone_id' => $this->zone_id,
            'zone' => $this->whenLoaded('zone', fn () => $this->zone?->name),
            'salary_info' => $this->whenLoaded('salaryInfo', fn () => $this->salaryInfo ? [
                'salary' => (float) $this->salaryInfo->salary,
                'account_name' => $this->salaryInfo->account_name,
                'account_number' => $this->salaryInfo->account_number,
                'fee' => (float) $this->salaryInfo->fee,
                'salary_type' => $this->salaryInfo->salary_type,
                'salary_type_label' => SalaryType::tryFrom((string) $this->salaryInfo->salary_type)?->label(),
                'commission_eligible' => (bool) $this->salaryInfo->commission_eligible,
                'payment_method' => $this->salaryInfo->payment_method,
            ] : null),
            'created_at' => $this->created_at?->toDateString(),
        ];
    }
}
