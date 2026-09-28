<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\V1\ApiController;
use App\Services\AccessControl;
use App\Services\Reports\ReportScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Shared filter handling for report endpoints: the live "Select Branch (incl. ALL) / From / To" modal, narrowed to the
 * branches the signed-in employee may see (the same company / zone / branch scope as ApiController::scoped()).
 */
abstract class ReportApiController extends ApiController
{
    protected function reportScope(Request $request, bool $defaultToToday = false): ReportScope
    {
        $request->validate([
            'branch_id' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $employee = $this->currentEmployee();
        $branchIds = app(AccessControl::class)->branchIds($employee);
        $branch = $request->input('branch_id');

        if (is_numeric($branch)) {
            $this->assertBranchAccessible((int) $branch);
            $branchIds = [(int) $branch];
        }

        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString())->startOfDay() : null;
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString())->startOfDay() : null;

        if ($defaultToToday && $from === null && $to === null) {
            $from = $to = CarbonImmutable::today();
        }
        if ($from !== null && $to !== null && $to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return new ReportScope((int) $employee->company_id, $branchIds, $from, $to);
    }

    /**
     * Echo of the applied filter for the page heading.
     *
     * @return array{branch_id: string, from: ?string, to: ?string}
     */
    protected function filterEcho(Request $request, ReportScope $scope): array
    {
        return [
            'branch_id' => is_numeric($request->input('branch_id')) ? (string) $request->input('branch_id') : 'all',
            'from' => $scope->from?->toDateString(),
            'to' => $scope->to?->toDateString(),
        ];
    }
}
