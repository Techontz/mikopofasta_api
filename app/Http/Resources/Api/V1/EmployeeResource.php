<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Employee;
use App\Services\AccessControl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
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
            'gender' => $this->gender,
            'position' => $this->position,
            'status' => $this->status,
            'account_type' => $this->account_type ?? Employee::ACCOUNT_STAFF,
            'must_change_password' => (bool) $this->must_change_password,
            'shareholder' => $this->whenLoaded('shareHolder', fn () => $this->shareHolder ? ['id' => $this->shareHolder->id, 'name' => $this->shareHolder->full_name] : null),
            'photo_url' => $this->photo_url,
            'company' => $this->whenLoaded('company', fn () => ['id' => $this->company->id, 'name' => $this->company->name, 'logo' => $this->company->logo]),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? ['id' => $this->branch->id, 'name' => $this->branch->name] : null),
            'zone' => $this->whenLoaded('zone', fn () => $this->zone ? ['id' => $this->zone->id, 'name' => $this->zone->name] : null),
            'role' => $this->whenLoaded('role', fn () => $this->role ? ['key' => $this->role->key, 'name' => $this->role->name, 'scope' => $this->role->scope] : null),
            'permissions' => $this->whenLoaded('role', fn () => app(AccessControl::class)->permissionsFor($this->resource)),
            'branch_ids' => $this->whenLoaded('role', fn () => app(AccessControl::class)->branchIds($this->resource)),
        ];
    }
}
