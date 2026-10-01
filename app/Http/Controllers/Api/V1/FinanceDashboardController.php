<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\AccessControl;
use App\Services\FinanceDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceDashboardController extends ApiController
{
    /**
     * GET /dashboard/finance?month=YYYY-MM&branch_id= — the Finance Dashboard ({@see FinanceDashboard}): a month of the
     * company's cash, collections, portfolio, income and expenses, for the whole company or one branch. Company money is
     * only for employees whose role covers the whole company and who may view the accounts.
     */
    public function __invoke(Request $request, FinanceDashboard $dashboard, AccessControl $access): JsonResponse
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

        return response()->json(['data' => $dashboard->build($this->currentCompany(), $employee, $month, $branchIds, $today)]);
    }
}
