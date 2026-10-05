<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\AccessControl;
use App\Services\CreditDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditDashboardController extends ApiController
{
    /**
     * GET /dashboard/credit?month=YYYY-MM&branch_id= — the Credit Department dashboard ({@see CreditDashboard}): a month
     * and a year of loan applications, the approval pipeline, today's collections and portfolio quality, for the
     * branches the employee may see or one of them. For employees who review credit.
     */
    public function __invoke(Request $request, CreditDashboard $dashboard, AccessControl $access): JsonResponse
    {
        $this->authorizeAny('dashboard.view');
        $this->authorizeAny('loans.credit_review');
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
