<?php

namespace App\Http\Controllers\Api\V1\Reports\Financial;

use App\Http\Controllers\Api\V1\ApiController;
use App\Services\AccessControl;
use App\Services\Reports\Financial\FinancialScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Base of the Reports → Financial endpoints: permission `reports.financial` and the live filter
 * (branch incl. ALL / HQ, from / to).
 */
abstract class FinancialReportController extends ApiController
{
    /**
     * Resolve the filter. Dates default to the first day of the current month … today.
     *
     * @throws ValidationException
     */
    protected function scope(Request $request, ?CarbonImmutable $defaultFrom = null): FinancialScope
    {
        $this->authorizeAny('reports.financial');

        $request->validate([
            'branch_id' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $employee = $this->currentEmployee();
        $visible = app(AccessControl::class)->branchIds($employee);
        $branch = (string) ($request->input('branch_id') ?? 'all');

        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString())->startOfDay() : CarbonImmutable::today();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString())->startOfDay() : ($defaultFrom ?? $to->startOfMonth());
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        if ($branch === 'hq') {
            abort_unless($visible === null, 403, 'You do not have access to this branch.');

            return new FinancialScope((int) $employee->company_id, [], true, $from, $to, 'HQ (COMPANY)');
        }

        if ($branch !== '' && $branch !== 'all') {
            abort_unless(ctype_digit($branch), 422, 'Invalid branch.');
            $this->assertBranchAccessible((int) $branch);

            return new FinancialScope((int) $employee->company_id, [(int) $branch], false, $from, $to, (string) $this->visibleBranches()->firstWhere('id', (int) $branch)?->name);
        }

        return new FinancialScope((int) $employee->company_id, $visible, $visible === null, $from, $to);
    }

    /**
     * Branch id → name map of the visible branches.
     *
     * @return array<int, string>
     */
    protected function branchNames(): array
    {
        return $this->visibleBranches()->pluck('name', 'id')->all();
    }

    /**
     * @return array{branch_id: string, label: string, from: string, to: string}
     */
    protected function filterMeta(Request $request, FinancialScope $scope): array
    {
        return [
            'branch_id' => (string) ($request->input('branch_id') ?? 'all'),
            'label' => $scope->label,
            'from' => $scope->from->toDateString(),
            'to' => $scope->to->toDateString(),
        ];
    }
}
