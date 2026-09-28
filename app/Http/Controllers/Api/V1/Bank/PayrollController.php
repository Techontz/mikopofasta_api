<?php

namespace App\Http\Controllers\Api\V1\Bank;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\SalaryPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Bank → Payroll (live admin/payrol_expenses and admin/view_expenses/{date}).
 * The HRM module owns the salary sheet, commission and staff fund; payroll is paid only from an
 * HR-approved payroll run (Documents: HR approves, Finance disburses). This page lists paid payrolls.
 */
class PayrollController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->authorizeAny('bank.manage', 'payroll.pay');

        $payrolls = SalaryPayment::where('company_id', $this->currentEmployee()->company_id)
            ->selectRaw('DATE(paid_on) as paid_date, MAX(paid_from_account) as paid_from_account, SUM(take_home) as total')
            ->groupByRaw('DATE(paid_on)')
            ->orderByDesc('paid_date')
            ->get()
            ->map(fn (SalaryPayment $row): array => [
                'date' => Carbon::parse($row->getAttribute('paid_date'))->toDateString(),
                'from_account' => $row->paid_from_account,
                'amount' => (float) $row->getAttribute('total'),
            ]);

        return response()->json(['data' => $payrolls, 'total' => round($payrolls->sum('amount'), 2)]);
    }

    public function show(string $date): JsonResponse
    {
        $this->authorizeAny('bank.manage', 'payroll.pay');
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);

        $payments = SalaryPayment::where('company_id', $this->currentEmployee()->company_id)
            ->whereDate('paid_on', $date)
            ->with('employee')
            ->orderBy('id')
            ->get()
            ->map(fn (SalaryPayment $payment): array => [
                'id' => $payment->id,
                'staff' => $payment->employee?->full_name,
                'salary' => (float) $payment->salary,
                'salary_advance' => (float) $payment->salary_advance,
                'allowance' => (float) $payment->allowance,
                'deduction' => (float) $payment->deduction,
                'loan_restoration' => (float) $payment->loan_restoration,
                'take_home' => (float) $payment->take_home,
                'phone' => $payment->phone,
                'account_name' => $payment->account_name,
                'account_number' => $payment->account_number,
                'date' => $payment->created_at?->format('Y-m-d H:i:s'),
            ]);

        return response()->json(['data' => $payments, 'date' => $date]);
    }
}
