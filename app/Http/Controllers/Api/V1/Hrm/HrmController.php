<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Employee;
use App\Services\AccessControl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Shared helpers for the HRM API controllers.
 */
abstract class HrmController extends ApiController
{
    protected function companyId(): int
    {
        return (int) $this->currentEmployee()->company_id;
    }

    /**
     * 404 for records of another company or outside the signed-in employee's branch scope.
     */
    protected function ensureVisible(Model $model, string $branchColumn = 'branch_id'): void
    {
        abort_unless((int) $model->getAttribute('company_id') === $this->companyId(), 404);
        abort_if($model instanceof Employee && $model->isShareholderAccount(), 404);

        $branchIds = app(AccessControl::class)->branchIds($this->currentEmployee());
        $branchId = $model->getAttribute($branchColumn);

        abort_unless($branchIds === null || ($branchId !== null && in_array((int) $branchId, $branchIds, true)), 404);
    }

    /**
     * Month from a "YYYY-MM" query/body value (defaults to the current month).
     */
    protected function month(Request $request, string $key = 'period'): CarbonImmutable
    {
        $value = $request->string($key)->toString();

        if (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            return CarbonImmutable::createFromFormat('Y-m-d', $value.'-01')->startOfMonth();
        }

        return CarbonImmutable::now()->startOfMonth();
    }

    /**
     * Whether the signed-in employee sees every branch (HQ roles).
     */
    protected function seesAllBranches(): bool
    {
        return app(AccessControl::class)->branchIds($this->currentEmployee()) === null;
    }
}
