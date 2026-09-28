<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Enums\SalaryType;
use App\Enums\StaffCreditStatus;
use App\Http\Resources\Api\V1\Hrm\MyAllowanceResource;
use App\Http\Resources\Api\V1\Hrm\MyBenefitClaimResource;
use App\Http\Resources\Api\V1\Hrm\MyNegligenceDeductionResource;
use App\Http\Resources\Api\V1\Hrm\MyPayslipResource;
use App\Http\Resources\Api\V1\Hrm\MyStaffDeductionResource;
use App\Models\CommissionAllocation;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\NegligenceDeduction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\SalaryPayment;
use App\Models\StaffAllowance;
use App\Models\StaffFundWithdrawal;
use App\Models\StaffLoan;
use App\Models\StaffLoanPayment;
use App\Models\StaffSalaryAdvance;
use App\Services\Hrm\CommissionPayments;
use App\Services\Hrm\StaffFund;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Employee Portal (spec §60, with the staff-visible parts of §21, §24 and §26): what a signed-in staff member sees about
 * THEMSELVES — payslips, staff fund contributions and benefit claims, staff loan / salary advance repayments, commission,
 * allowances, other deductions and negligence recoveries.
 *
 * No permission is needed and every query is scoped to the signed-in employee; any employee id in the request is ignored.
 * Central balances (fund cash, company / branch accounts, payroll or commission totals, other staff) are never returned.
 * Shareholder portal logins are refused (the account boundary middleware already does; this is a second guard).
 */
class MyPortalController extends HrmController
{
    /** Commission fields a staff member may see about their own commission (no branch profit, pool or paying account). */
    private const COMMISSION_FIELDS = [
        'id', 'period', 'period_label', 'closing_date', 'branch', 'kind', 'share_percent', 'calculated_amount', 'negligence_deduction',
        'negligence_expected', 'net_commission', 'status', 'status_label', 'requested_at', 'approved_at', 'rejected_at', 'rejection_reason',
        'paid_at', 'paid_on',
    ];

    public function __construct(
        private readonly StaffFund $fund,
        private readonly CommissionPayments $commissionPayments,
    ) {}

    public function overview(): JsonResponse
    {
        $employee = $this->employee()->load(['branch', 'role', 'salaryInfo']);
        $payslips = $this->payslipRows($employee);
        $latestPayslip = $payslips->first();
        $commission = $this->commissionRows($employee);
        $latestCommission = $commission->first();
        $loans = $employee->staffLoans()->withSum('payments', 'amount')->get();
        $advances = $employee->salaryAdvances()->get();
        $allowances = $employee->allowances()->get();
        $benefit = $this->fund->entitlement($this->companyId(), $employee->id);

        return response()->json(['data' => [
            'employee' => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_number' => $employee->employee_number,
                'position' => $employee->role?->name ?? $employee->position,
                'branch' => $employee->branch?->name,
                'salary_structure' => SalaryType::tryFrom((string) $employee->salaryInfo?->salary_type)?->label(),
                'basic_salary' => (float) ($employee->salaryInfo?->salary ?? 0),
            ],
            'salary' => [
                'latest' => $latestPayslip === null ? null : Arr::only($latestPayslip, ['payslip_id', 'period_label', 'net_salary', 'payment_status', 'payment_status_label', 'paid_on']),
                'awaiting_payment_count' => $payslips->where('payment_status', MyPayslipResource::STATUS_AWAITING_PAYMENT)->count(),
            ],
            'staff_fund' => [
                'staff_contribution' => $benefit['benefit_record'],
                'total_benefit_record' => $benefit['benefit_record'],
                'open_claims' => $benefit['open_claims'],
            ],
            'staff_loans' => [
                'active_count' => $loans->whereIn('status', StaffCreditStatus::recovering())->count(),
                'outstanding' => round((float) $loans->whereIn('status', StaffCreditStatus::recovering())->sum(fn (StaffLoan $loan): float => $loan->remainingAmount()), 2),
                'pending_requests' => $loans->whereIn('status', StaffCreditStatus::awaitingDisbursement())->count(),
            ],
            'salary_advances' => [
                'active_count' => $advances->whereIn('status', StaffCreditStatus::recovering())->count(),
                'outstanding' => round((float) $advances->whereIn('status', StaffCreditStatus::recovering())->sum(fn (StaffSalaryAdvance $advance): float => $advance->outstandingAmount()), 2),
                'pending_requests' => $advances->whereIn('status', StaffCreditStatus::awaitingDisbursement())->count(),
            ],
            'commission' => [
                'latest' => $latestCommission === null ? null : Arr::only($latestCommission, ['period_label', 'net_commission', 'status', 'status_label', 'paid_on']),
                'unpaid_net' => round((float) $commission->whereNotIn('status', [CommissionAllocation::STATUS_PAID, CommissionAllocation::STATUS_PAYROLL])->sum('net_commission'), 2),
            ],
            'allowances' => [
                'awaiting_payroll_count' => $allowances->where('status', StaffAllowance::STATUS_APPROVED)->count(),
                'awaiting_payroll_amount' => round((float) $allowances->where('status', StaffAllowance::STATUS_APPROVED)->sum('amount'), 2),
                'pending_approval_count' => $allowances->where('status', StaffAllowance::STATUS_PENDING)->count(),
            ],
            'negligence' => [
                'outstanding' => round((float) $this->approvedNegligence($employee)->sum(fn (NegligenceDeduction $deduction): float => $deduction->outstandingAmount()), 2),
            ],
        ]]);
    }

    /**
     * Payslips per payroll period, newest first: paid salary payments and the employee's lines on approved, unpaid payrolls.
     */
    public function payslips(): JsonResponse
    {
        $employee = $this->employee();
        $rows = $this->payslipRows($employee);

        return response()->json(['data' => [
            'summary' => [
                'total_net_paid' => round((float) $rows->where('payment_status', MyPayslipResource::STATUS_PAID)->sum('net_salary'), 2),
                'awaiting_payment_count' => $rows->where('payment_status', MyPayslipResource::STATUS_AWAITING_PAYMENT)->count(),
            ],
            'payslips' => $rows->values()->all(),
        ]]);
    }

    /**
     * Spec §26 (product owner ruling: the 20 % withheld from basic salary is the only contribution — there is no company
     * contribution) and §27: the employee's own contributions, benefit record and benefit claims. Never the fund cash balance.
     */
    public function staffFund(): JsonResponse
    {
        $employee = $this->employee();
        $companyId = $this->companyId();
        $entitlement = $this->fund->entitlement($companyId, $employee->id);
        $claims = StaffFundWithdrawal::where('company_id', $companyId)->where('employee_id', $employee->id)->latest('id')->get();
        $benefitsPaid = round((float) $claims->where('status', StaffFundWithdrawal::STATUS_PAID)->sum('amount'), 2);

        $contributions = $employee->salaryPayments()
            ->where('company_id', $companyId)
            ->where('staff_fund', '>', 0)
            ->with('payrollRun')
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SalaryPayment $payment): array => [
                'payslip_id' => $payment->id,
                'payroll_period' => ($payment->payrollRun?->period ?? $payment->paid_on)?->format('Y-m'),
                'period_label' => ($payment->payrollRun?->period ?? $payment->paid_on)?->format('F Y'),
                'basic_salary' => (float) $payment->salary,
                'staff_contribution' => (float) $payment->staff_fund,
                'paid_on' => $payment->paid_on?->toDateString(),
            ])->values();

        return response()->json(['data' => [
            'summary' => [
                'contribution_percent' => (float) HrmSetting::forCompany($companyId)->staff_fund_percent,
                'total_contributions' => round($entitlement['benefit_record'] + $benefitsPaid, 2),
                'benefits_paid' => $benefitsPaid,
                'staff_contribution' => $entitlement['benefit_record'],
                'total_benefit_record' => $entitlement['benefit_record'],
                'open_claims' => $entitlement['open_claims'],
                'claimable' => $entitlement['claimable'],
            ],
            'contributions' => $contributions->all(),
            'claims' => MyBenefitClaimResource::collection($claims)->resolve(),
        ]]);
    }

    /**
     * Spec §21 / §22 / §23: monthly commission per period with the zone deduction where applicable, the negligence deduction, net
     * commission and the payment status and dates.
     */
    public function commission(): JsonResponse
    {
        $rows = $this->commissionRows($this->employee());

        return response()->json(['data' => [
            'summary' => [
                'total_net_paid' => round((float) $rows->where('status', CommissionAllocation::STATUS_PAID)->sum('net_commission'), 2),
                'total_net_unpaid' => round((float) $rows->whereNotIn('status', [CommissionAllocation::STATUS_PAID, CommissionAllocation::STATUS_PAYROLL])->sum('net_commission'), 2),
            ],
            'commissions' => $rows->values()->all(),
        ]]);
    }

    /**
     * Spec §24: allowances (reason, amount, payroll period, status — "Approved / Awaiting Payroll") and other salary deductions.
     */
    public function allowances(): JsonResponse
    {
        $employee = $this->employee();
        $allowances = $employee->allowances()->where('company_id', $this->companyId())->with('payrollRun')->latest('id')->get();
        $deductions = $employee->deductions()->where('company_id', $this->companyId())->latest('id')->get();

        return response()->json(['data' => [
            'summary' => [
                'awaiting_payroll_count' => $allowances->where('status', StaffAllowance::STATUS_APPROVED)->count(),
                'awaiting_payroll_amount' => round((float) $allowances->where('status', StaffAllowance::STATUS_APPROVED)->sum('amount'), 2),
                'pending_approval_count' => $allowances->where('status', StaffAllowance::STATUS_PENDING)->count(),
                'paid_amount' => round((float) $allowances->where('status', StaffAllowance::STATUS_PAID)->sum('amount'), 2),
            ],
            'allowances' => MyAllowanceResource::collection($allowances)->resolve(),
            'deductions' => MyStaffDeductionResource::collection($deductions)->resolve(),
        ]]);
    }

    /**
     * Spec §23: Finance-approved negligence / loss deductions of the employee (pending or rejected ones are not an approved HR
     * record yet) with each recovery from commission and the balance carried forward.
     */
    public function negligence(): JsonResponse
    {
        $deductions = $this->approvedNegligence($this->employee(), withRecoveries: true);

        return response()->json(['data' => [
            'summary' => [
                'total' => round((float) $deductions->sum('amount'), 2),
                'recovered' => round((float) $deductions->sum('recovered_amount'), 2),
                'outstanding' => round((float) $deductions->sum(fn (NegligenceDeduction $deduction): float => $deduction->outstandingAmount()), 2),
            ],
            'deductions' => MyNegligenceDeductionResource::collection($deductions)->resolve(),
        ]]);
    }

    /**
     * Spec §29 / §30: approved amount, outstanding balance, repayment schedule and the payroll deductions of the employee's staff
     * loans and salary advances (requests themselves: `hrm/my/staff-loans`, `hrm/my/salary-advances`).
     */
    public function repayments(): JsonResponse
    {
        $employee = $this->employee();
        $loans = $employee->staffLoans()
            ->where('company_id', $this->companyId())
            ->with(['category', 'payments' => fn ($query) => $query->orderBy('paid_on')->orderBy('id')])
            ->withSum('payments', 'amount')
            ->latest('id')
            ->get();
        $advances = $employee->salaryAdvances()->where('company_id', $this->companyId())->with('category')->latest('id')->get();
        $advanceDeductions = $employee->salaryPayments()
            ->where('company_id', $this->companyId())
            ->where('salary_advance', '>', 0)
            ->with('payrollRun')
            ->orderByDesc('paid_on')
            ->get()
            ->map(fn (SalaryPayment $payment): array => [
                'payslip_id' => $payment->id,
                'period_label' => ($payment->payrollRun?->period ?? $payment->paid_on)?->format('F Y'),
                'amount' => (float) $payment->salary_advance,
                'paid_on' => $payment->paid_on?->toDateString(),
            ])->values();

        return response()->json(['data' => [
            'staff_loans' => $loans->map(fn (StaffLoan $loan): array => [
                'id' => $loan->id,
                'category' => $loan->category?->name,
                'amount_applied' => (float) $loan->amount_applied,
                'amount_approved' => (float) $loan->amount_approved,
                'total_payable' => (float) $loan->total_payable,
                'instalment' => (float) $loan->restoration,
                'sessions' => $loan->sessions,
                'paid_amount' => $loan->paidAmount(),
                'outstanding' => in_array($loan->status, StaffCreditStatus::recovering(), true) ? $loan->remainingAmount() : 0.0,
                'status' => $loan->status,
                'status_label' => StaffCreditStatus::tryFrom((string) $loan->status)?->label() ?? $loan->status,
                'disbursed_at' => $loan->disbursed_at?->toDateString(),
                'schedule' => $this->loanSchedule($loan),
                'deductions' => $loan->payments->map(fn (StaffLoanPayment $payment): array => ['amount' => (float) $payment->amount, 'paid_on' => $payment->paid_on?->toDateString()])->values()->all(),
            ])->values()->all(),
            'salary_advances' => $advances->map(fn (StaffSalaryAdvance $advance): array => [
                'id' => $advance->id,
                'category' => $advance->category?->name,
                'amount' => (float) $advance->amount,
                'fee' => (float) $advance->fee,
                'recovered_amount' => (float) $advance->recovered_amount,
                'outstanding' => in_array($advance->status, StaffCreditStatus::recovering(), true) ? $advance->outstandingAmount() : 0.0,
                'status' => $advance->status,
                'status_label' => StaffCreditStatus::tryFrom((string) $advance->status)?->label() ?? $advance->status,
                'disbursed_at' => $advance->disbursed_at?->toDateString(),
            ])->values()->all(),
            'salary_advance_deductions' => $advanceDeductions->all(),
        ]]);
    }

    /**
     * The signed-in staff employee; a shareholder portal login is refused.
     */
    private function employee(): Employee
    {
        $employee = $this->currentEmployee();
        abort_if($employee->isShareholderAccount(), 403, 'Shareholder accounts can only use the Shareholder Portal.');

        return $employee;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function payslipRows(Employee $employee): Collection
    {
        $companyId = $this->companyId();
        $paid = SalaryPayment::where('company_id', $companyId)->where('employee_id', $employee->id)->with('payrollRun')->get();
        $awaiting = PayrollItem::where('employee_id', $employee->id)
            ->whereNull('salary_payment_id')
            ->whereHas('run', fn ($query) => $query->where('company_id', $companyId)->where('status', PayrollRun::STATUS_APPROVED))
            ->with('run')
            ->get();

        return collect(MyPayslipResource::collection($awaiting)->resolve())
            ->concat(MyPayslipResource::collection($paid)->resolve())
            ->sortByDesc(fn (array $row): string => ($row['payroll_period'] ?? '').'|'.($row['paid_on'] ?? '9999').'|'.str_pad((string) ($row['payslip_id'] ?? 0), 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * The employee's commission rows, newest period first, reduced to {@see COMMISSION_FIELDS} plus the zone deduction.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function commissionRows(Employee $employee): Collection
    {
        $allocations = $this->commissionPayments->select($this->companyId(), null, null, null, (int) $employee->id)->sortByDesc('accounting_period_id');
        $outstanding = $this->commissionPayments->outstandingNegligence([(int) $employee->id]);

        return $allocations->map(function (CommissionAllocation $allocation) use ($outstanding): array {
            $row = $this->commissionPayments->present($allocation, $outstanding);
            $zoneManager = $allocation->kind === CommissionAllocation::KIND_ZONE_MANAGER;

            return Arr::only($row, self::COMMISSION_FIELDS) + [
                'kind_label' => $zoneManager ? 'Zone Manager' : 'Branch Staff',
                'zone_deduction' => ! $zoneManager && $row['zone_allocation'] !== null ? (float) $row['zone_allocation'] : null,
            ];
        })->values();
    }

    /**
     * @return EloquentCollection<int, NegligenceDeduction>
     */
    private function approvedNegligence(Employee $employee, bool $withRecoveries = false): EloquentCollection
    {
        return NegligenceDeduction::where('company_id', $this->companyId())
            ->where('employee_id', $employee->id)
            ->whereIn('status', [NegligenceDeduction::STATUS_APPROVED, NegligenceDeduction::STATUS_RECOVERING, NegligenceDeduction::STATUS_RECOVERED])
            ->when($withRecoveries, fn ($query) => $query->with('recoveries'))
            ->latest('id')
            ->get();
    }

    /**
     * Instalments of a disbursed staff loan (one per payroll): the configured instalment, the last one taking the remainder of the
     * loan + interest, with what the payroll deductions (applied oldest first) have covered.
     *
     * @return list<array{number: int, amount: float, paid: float, balance: float, status: string}>
     */
    private function loanSchedule(StaffLoan $loan): array
    {
        $sessions = (int) $loan->sessions;
        $total = (float) $loan->total_payable;
        $instalment = (float) $loan->restoration;

        if ($loan->disbursed_at === null || $sessions < 1 || $total <= 0) {
            return [];
        }

        $paidLeft = $loan->paidAmount();
        $scheduled = 0.0;
        $schedule = [];

        for ($number = 1; $number <= $sessions; $number++) {
            $amount = $number === $sessions || $instalment <= 0 ? round($total - $scheduled, 2) : round(min($instalment, $total - $scheduled), 2);
            if ($amount <= 0) {
                break;
            }
            $scheduled = round($scheduled + $amount, 2);
            $paid = round(min($amount, max(0.0, $paidLeft)), 2);
            $paidLeft = round($paidLeft - $paid, 2);

            $schedule[] = [
                'number' => $number,
                'amount' => $amount,
                'paid' => $paid,
                'balance' => round($total - $scheduled, 2),
                'status' => $paid >= $amount ? 'paid' : ($paid > 0 ? 'partly_paid' : 'due'),
            ];
        }

        return $schedule;
    }
}
