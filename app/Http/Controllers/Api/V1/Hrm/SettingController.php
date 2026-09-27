<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Http\Requests\Api\Hrm\HrmSettingsRequest;
use App\Models\HrmSetting;
use App\Models\Role;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;

/**
 * HRM parameters and dropdown options (roles, zones).
 */
class SettingController extends HrmController
{
    public function show(): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'payroll.approve', 'payroll.pay', 'reports.financial');

        $settings = HrmSetting::forCompany($this->companyId());

        return response()->json(['data' => [
            'commission_pool_percent' => (float) $settings->commission_pool_percent,
            'zone_override_percent' => (float) $settings->zone_override_percent,
            'staff_fund_percent' => (float) $settings->staff_fund_percent,
            'work_start_time' => $settings->work_start_time,
        ]]);
    }

    public function update(HrmSettingsRequest $request): JsonResponse
    {
        $this->authorizeAny('payroll.approve');

        HrmSetting::forCompany($this->companyId())->update($request->validated());

        return $this->message('Settings Saved successfully');
    }

    public function roles(): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'users.manage');

        return response()->json(['data' => Role::where('company_id', $this->companyId())->orderBy('id')->get()->map(fn (Role $role): array => [
            'value' => (string) $role->id,
            'label' => $role->name,
            'scope' => $role->scope,
        ])]);
    }

    public function zones(): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'users.manage');

        return response()->json(['data' => Zone::where('company_id', $this->companyId())->orderBy('name')->get()->map(fn (Zone $zone): array => [
            'value' => (string) $zone->id,
            'label' => $zone->name,
        ])]);
    }
}
