<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\AccessControl;
use App\Services\DashboardStatistics;
use App\Services\FinanceDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceDashboardController extends ApiController
{
    /**
     * GET /dashboard/finance?month=YYYY-MM&branch_id= — the Finance Dashboard ({@see FinanceDashboard}): a month of the
     * company's cash, collections, portfolio, income and expenses, for the whole company or one branch. Company money is
     * only for employees whose role covers the whole company and who may view the accounts. branch_accounts is the
     * "Branch List" popup ({@see DashboardStatistics::branchAccounts()}) for the chosen month: always every branch.
     */
    public function __invoke(Request $request, FinanceDashboard $dashboard, DashboardStatistics $statistics, AccessControl $access): JsonResponse
    {
        $this->authorizeAny('dashboard.view');
        $employee = $this->currentEmployee();
        abort_unless($access->branchIds($employee) === null && $employee->can('accounting.view'), 403, 'You do not have permission to perform this action.');

        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        $today = CarbonImmutable::today();
        $month = isset($data['month']) ? CarbonImmutable::createFromFormat('!Y-m', $data['month']) : $today;
        $branchIds = null;
        if (isset($data['branch_id'])) {
            $this->assertBranchAccessible((int) $data['branch_id']);
            $branchIds = [(int) $data['branch_id']];
        }

        $company = $this->currentCompany();

        return response()->json(['data' => [
            ...$dashboard->build($company, $employee, $month, $branchIds, $today),
            'branch_accounts' => $statistics->branchAccounts($company, $month),
        ]]);
    }
}
