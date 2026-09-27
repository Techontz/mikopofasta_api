<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Enums\SalaryType;
use App\Http\Resources\Api\V1\Hrm\SalaryPaymentResource;
use App\Models\ApprovalPolicy;
use App\Models\PayrollRun;
use App\Models\SalaryPayment;
use App\Services\AccessControl;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Hrm\CommissionEngine;
use App\Services\Hrm\PayrollEngine;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * HRM → Salary Sheet (live admin/salary_sheet) with the Documents' payroll workflow:
 * HR prepares and reviews (payroll.approve: generate, commission, settings) → Finance is the final approver and pays
 * (payroll.pay) — product owner ruling 2026-09-17 on specification §31; payslips.
 */
class PayrollController extends HrmController
{
    private const MONEY = ['base_salary', 'commission', 'allowance', 'gross', 'staff_fund', 'salary_advance', 'deduction', 'negligence', 'loan_restoration', 'total_deductions', 'take_home'];

    public function __construct(
        private readonly PayrollEngine $payroll,
        private readonly CommissionEngine $commission,
    ) {}

    /**
     * The month's payroll run (stored lines) or, before generation, a live preview of the sheet.
     */
    public function show(Request $request): JsonResponse
    {
        $this->authorizeAny('payroll.approve', 'payroll.pay');

        $month = $this->month($request);
        $run = $this->payroll->run($this->companyId(), $month);
        $branchIds = app(AccessControl::class)->branchIds($this->currentEmployee());
        $expense = $run?->expense_date !== null
            ? ['date' => $run->expense_date->toDateString(), 'note' => $run->expense_period_note]
            : collect($this->payroll->expenseDate($run ?? new PayrollRun(['company_id' => $this->companyId(), 'period' => $month->toDateString()])))
                ->map(fn ($value) => $value instanceof CarbonImmutable ? $value->toDateString() : $value)->all();

        $rows = $run === null
            ? $this->payroll->preview($this->companyId(), $month)
            : $run->items()->with('employee', 'branch')->orderBy('id')->get()->map(fn ($item): array => $item->only([
                'employee_id', 'branch_id', 'salary_type', 'base_salary', 'commission', 'allowance', 'gross', 'staff_fund', 'salary_advance',
                'deduction', 'negligence', 'loan_restoration', 'total_deductions', 'take_home', 'phone', 'account_name', 'account_number', 'payment_method', 'salary_payment_id',
            ]) + ['employee' => $item->employee?->full_name, 'branch' => $item->branch?->name]);

        $rows = collect($rows)
            ->filter(fn (array $row): bool => $branchIds === null || in_array((int) $row['branch_id'], $branchIds, true))
            ->map(fn (array $row): array => array_merge(collect($row)->except('allowance_ids')->all(), collect(self::MONEY)->mapWithKeys(fn (string $key): array => [$key => (float) $row[$key]])->all(), [
                'net_commission' => round((float) $row['commission'] - (float) $row['negligence'], 2),
                'paying_account' => $this->payroll->payingAccount($row)['account']->label(),
                'salary_type_label' => SalaryType::tryFrom((string) $row['salary_type'])?->label(),
            ]))
            ->values();

        return response()->json(['data' => [
            'period' => $month->format('Y-m'),
            'period_label' => $month->format('F, Y'),
            'period_closed' => $this->commission->closedPeriod($this->companyId(), $month) !== null,
            'expense_date' => $expense['date'],
            'expense_period_note' => $expense['note'],
            'run' => $run ? [
                'id' => $run->id,
                'status' => $run->status,
                'commission_status' => $run->commission_status,
                'total_gross' => (float) $run->total_gross,
                'total_deductions' => (float) $run->total_deductions,
                'total_net' => (float) $run->total_net,
                'prepared_by' => $run->preparer?->full_name,
                'approved_by' => $run->approver?->full_name,
                'approved_at' => $run->approved_at?->toDateTimeString(),
                'paid_by' => $run->payer?->full_name,
                'paid_at' => $run->paid_at?->toDateTimeString(),
                'expense_date' => $run->expense_date?->toDateString(),
                'expense_period_note' => $run->expense_period_note,
                ...app(SegregationOfDuties::class)->flags($run->prepared_by, $this->currentEmployee(), $run->status === PayrollRun::STATUS_DRAFT, Gate::allows('payroll.pay'), workflow: ApprovalPolicy::PAYROLL),
                ...collect(app(SegregationOfDuties::class)->flags($run->prepared_by, $this->currentEmployee(), $run->status === PayrollRun::STATUS_APPROVED, Gate::allows('payroll.pay'), workflow: ApprovalPolicy::PAYROLL))
                    ->mapWithKeys(fn ($value, string $key): array => [str_replace('approve', 'pay', $key) => $value])->all(),
            ] : null,
            'rows' => $rows,
        ]]);
    }

    public function generate(Request $request): JsonResponse
    {
        $this->authorizeAny('payroll.approve');
        $request->validate(['period' => ['required', 'date_format:Y-m']]);

        $run = $this->payroll->generate($this->companyId(), $this->month($request), $this->currentEmployee());

        return $this->message('Payroll Generated successfully', 200, ['data' => ['id' => $run->id, 'status' => $run->status]]);
    }

    /**
     * Finance gives the final approval (§31: HR prepares payroll but never creates the money movement alone), and rule 6
     * still applies: the employee who generated (prepared) the payroll cannot approve it.
     */
    public function approve(PayrollRun $run, SegregationOfDuties $duties): JsonResponse
    {
        $this->authorizeAny('payroll.pay');
        abort_unless($run->company_id === $this->companyId(), 404);
        $duties->assertCanApprove($run->prepared_by, $this->currentEmployee(), 'payroll', workflow: ApprovalPolicy::PAYROLL);

        $this->payroll->approve($run, $this->currentEmployee());

        return $this->message('Payroll Approved successfully');
    }

    /**
     * "Pay Salary" modal. Branch staff are paid from their branch INTEREST ACC, HQ staff from the COMPANY ACCOUNT.
     * Rule 6: approving is the authorisation of the payment, so the payer must differ from the preparer (the approver
     * already differs from the preparer); an approver who also pays is allowed.
     */
    public function pay(Request $request, PayrollRun $run, SegregationOfDuties $duties): JsonResponse
    {
        $this->authorizeAny('payroll.pay');
        abort_unless($run->company_id === $this->companyId(), 404);
        $duties->assertCanApprove($run->prepared_by, $this->currentEmployee(), 'payroll payment', workflow: ApprovalPolicy::PAYROLL);
        $request->validate(['ac_id' => ['required', Rule::in(['interest'])]]);

        $this->payroll->pay($run, $this->currentEmployee());

        return $this->message('Salary Paid successfully');
    }

    /**
     * "Salary Paid" statement (live filter_salary_paid) and salary slips.
     */
    public function payments(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('payroll.approve', 'payroll.pay', 'hrm.manage');

        $branchIds = app(AccessControl::class)->branchIds($this->currentEmployee());

        $payments = SalaryPayment::where('company_id', $this->companyId())
            ->with(['employee.role', 'branch', 'payrollRun'])
            ->when($branchIds !== null, fn ($query) => $query->whereHas('employee', fn ($inner) => $inner->whereIn('branch_id', $branchIds)))
            ->when($request->filled('employee_id'), fn ($query) => $query->where('employee_id', $request->integer('employee_id')))
            ->when($request->date('from'), fn ($query, $from) => $query->whereDate('paid_on', '>=', $from->toDateString()))
            ->when($request->date('to'), fn ($query, $to) => $query->whereDate('paid_on', '<=', $to->toDateString()))
            ->latest('id')
            ->get();

        return SalaryPaymentResource::collection($payments);
    }

    /**
     * Payslip (OVERVIEW ALL REPORT "Staff Payslip"). Staff may always open their own slip.
     */
    public function payslip(SalaryPayment $payment): JsonResponse
    {
        abort_unless($payment->company_id === $this->companyId(), 404);
        if ($payment->employee_id !== $this->currentEmployee()->id) {
            $this->authorizeAny('payroll.approve', 'payroll.pay', 'hrm.manage');
            $this->ensureVisible($payment->employee);
        }

        $payment->load(['employee.role', 'branch', 'payrollRun']);
        $company = $this->currentCompany();

        return response()->json(['data' => (new SalaryPaymentResource($payment))->resolve() + [
            'company' => ['name' => $company->name, 'address' => $company->address, 'phone' => $company->phone, 'email' => $company->email],
        ]]);
    }
}
