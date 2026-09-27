<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Enums\Duration;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Settings\LoanCategoryRequest;
use App\Models\CustomerCategory;
use App\Models\InterestFormula;
use App\Models\LoanCategory;
use App\Models\Role;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * {value,label} dropdown lists used by the Settings pages.
 */
class SettingsOptionController extends ApiController
{
    public function formulas(): JsonResponse
    {
        return $this->options(InterestFormula::where('is_enabled', true)->orderBy('id')->get()->map(fn (InterestFormula $formula): array => ['value' => $formula->code, 'label' => $formula->name]));
    }

    public function durations(): JsonResponse
    {
        return $this->options(collect(Duration::cases())->map(fn (Duration $duration): array => ['value' => $duration->value, 'label' => $duration->label()]));
    }

    public function approveLevels(): JsonResponse
    {
        return $this->options(collect(LoanCategoryRequest::APPROVE_LEVELS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values());
    }

    public function loanCategories(): JsonResponse
    {
        return $this->options(LoanCategory::where('company_id', $this->currentEmployee()->company_id)->orderBy('id')->get()->map(fn (LoanCategory $category): array => ['value' => (string) $category->id, 'label' => $category->name]));
    }

    public function customerCategories(): JsonResponse
    {
        return $this->options(CustomerCategory::query()->selectable($this->currentEmployee()->company_id)->get()->map(fn (CustomerCategory $category): array => ['value' => (string) $category->id, 'label' => $category->name]));
    }

    public function zones(): JsonResponse
    {
        return $this->options(Zone::where('company_id', $this->currentEmployee()->company_id)->orderBy('name')->get()->map(fn (Zone $zone): array => ['value' => (string) $zone->id, 'label' => $zone->name]));
    }

    public function roles(): JsonResponse
    {
        return $this->options(Role::where('company_id', $this->currentEmployee()->company_id)->orderBy('id')->get()->map(fn (Role $role): array => ['value' => (string) $role->id, 'label' => $role->name]));
    }

    /**
     * @param  Collection<int, array{value: string, label: string}>  $options
     */
    private function options(Collection $options): JsonResponse
    {
        $this->authorizeAny('settings.manage', 'users.manage', 'hrm.manage');

        return response()->json(['data' => $options->values()]);
    }
}
