<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Hrm\HrDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrDashboardController extends ApiController
{
    /**
     * GET /dashboard/hr?month=YYYY-MM — the HR Department dashboard ({@see HrDashboard}): staff, the month's payroll and
     * the staff requests waiting for a decision, for the whole company. For employees who manage HR.
     */
    public function __invoke(Request $request, HrDashboard $dashboard): JsonResponse
    {
        $this->authorizeAny('dashboard.view');
        $this->authorizeAny('hrm.manage');

        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = isset($data['month']) ? CarbonImmutable::createFromFormat('!Y-m', $data['month']) : CarbonImmutable::today();

        return response()->json(['data' => $dashboard->build((int) $this->currentEmployee()->company_id, $month)]);
    }
}
