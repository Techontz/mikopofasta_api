<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\AccessControl;
use App\Services\ZoneDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZoneDashboardController extends ApiController
{
    /**
     * GET /dashboard/zone?month=YYYY-MM&branch_id= — the Zone Manager dashboard ({@see ZoneDashboard}): the Branch dashboard
     * of the branches the employee may see (a zone manager's zone) or one of them, with a performance row per branch.
     */
    public function __invoke(Request $request, ZoneDashboard $dashboard, AccessControl $access): JsonResponse
    {
        $this->authorizeAny('dashboard.view');
        $this->authorizeAny('loans.view');
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
