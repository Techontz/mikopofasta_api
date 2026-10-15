<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\AccessControl;
use App\Services\BranchDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchDashboardController extends ApiController
{
    /**
     * GET /dashboard/branch?month=YYYY-MM&branch_id= — the Branch dashboard ({@see BranchDashboard}) of Tellers, Loan
     * Officers and Branch Managers: the loan book, petty cash, today's collection and disbursement, the portfolio per
     * customer type, the approval queue and the month's pipeline, for the branches the employee may see or one of them.
     */
    public function __invoke(Request $request, BranchDashboard $dashboard, AccessControl $access): JsonResponse
    {
        $this->authorizeAny('dashboard.view');
        $employee = $this->currentEmployee();

        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        $today = CarbonImmutable::today();
        $month = isset($data['month']) ? CarbonImmutable::createFromFormat('!Y-m', $data['month']) : $today;
        $branchIds = $access->branchIds($employee);
        if (isset($data['branch_id'])) {
            $this->assertBranchAccessible((int) $data['branch_id']);
            $branchIds = [(int) $data['branch_id']];
        }

        return response()->json(['data' => $dashboard->build($this->currentCompany(), $employee, $month, $branchIds, $today)]);
    }
}
