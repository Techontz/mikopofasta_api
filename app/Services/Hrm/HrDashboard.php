<?php

namespace App\Services\Hrm;

use App\Enums\StaffCreditStatus;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\PayrollRun;
use App\Models\SalaryChangeRequest;
use App\Models\StaffAllowance;
use App\Models\StaffFundWithdrawal;
use App\Models\StaffLoan;
use App\Models\StaffSalaryAdvance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HR Department dashboard: the staff, the payroll of a month and the staff requests waiting for a decision, every figure
 * from the HRM records:
 *
 *  - staff: staff logins (shareholder portal logins excluded) registered by the end of the month, rejected registrations
 *    excluded; active = status ACTIVE. There is no hire date, so "new staff" are the ones registered in the month. Status
 *    is today's, so last month's figures count today's status of the staff registered by then;
 *  - department: the staff member's role (there is no department register);
 *  - pending requests: staff loans and salary advances not yet paid out, allowances waiting for Finance, staff fund claims
 *    not yet paid, leave and salary change requests waiting for a decision. "Pending" = not reviewed yet, "Under Review" =
 *    reviewed by HR and waiting for the next approver;
 *  - payroll: the month's payroll run (draft, approved or paid); before it is generated, the live preview of the salary
 *    sheet ({@see PayrollEngine::preview()}). The staff fund line is the staff's contribution deducted from salary — the
 *    company contribution was removed by the product owner;
 *  - staff fund: per month, the contributions deducted in that month's payroll, the claims paid in it and the claims made
 *    in it that are still open.
 */
final class HrDashboard
{
    private const TREND_MONTHS = 6;

    private const LIST_LIMIT = 50;

    public function __construct(private readonly PayrollEngine $payroll) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $companyId, CarbonImmutable $month): array
    {
        $end = $month->endOfMonth();
        $previousEnd = $month->subMonthNoOverflow()->endOfMonth();
        $requests = $this->pendingRequests($companyId);
        $lines = $this->payrollLines($companyId, $month);
        $run = $this->payroll->run($companyId, $month);

        $count = fn (string $type): int => $requests->where('type', $type)->count();
        $amount = fn (string $type): float => round((float) $requests->where('type', $type)->sum('amount'), 2);
        $staff = fn (CarbonImmutable $at, bool $active) => $this->staff($companyId)->where('created_at', '<=', $at)->when($active, fn ($query) => $query->where('status', 'active'))->count();
        $newStaff = fn (CarbonImmutable $in): int => $this->staff($companyId)->whereBetween('created_at', [$in->startOfMonth(), $in->endOfMonth()])->count();

        return [
            'month' => $month->format('Y-m'),
            'month_label' => $month->format('F Y'),
            'cards' => [
                'total_staff' => $staff($end, false),
                'total_staff_change' => $this->change($staff($end, false), $staff($previousEnd, false)),
                'active_staff' => $staff($end, true),
                'active_staff_change' => $this->change($staff($end, true), $staff($previousEnd, true)),
                'new_staff' => $newStaff($month),
                'new_staff_change' => $this->change($newStaff($month), $newStaff($month->subMonthNoOverflow())),
                'pending_requests' => $requests->count(),
                'pending_requests_amount' => round((float) $requests->sum('amount'), 2),
                'pending_allowances' => $count('allowance'),
                'pending_allowances_amount' => $amount('allowance'),
                'pending_staff_loans' => $count('staff_loan') + $count('salary_advance'),
                'pending_staff_loans_amount' => round($amount('staff_loan') + $amount('salary_advance'), 2),
                'pending_claims' => $count('benefit_claim'),
                'pending_claims_amount' => $amount('benefit_claim'),
                'payroll_net' => round((float) $lines->sum('take_home'), 2),
                'payroll_employees' => $lines->count(),
                'payroll_status' => $run ? ucfirst($run->status) : 'Not generated',
            ],
            'departments' => $this->departments($companyId),
            'payroll_trend' => $this->payrollTrend($companyId, $month, (float) $lines->sum('take_home')),
            'requests_by_type' => $this->requestsByType($companyId, $month),
            'staff_fund' => $this->staffFundTrend($companyId, $month),
            'payroll_summary' => $this->payrollSummary($lines),
            'pending' => $requests->sortByDesc('date')->take(self::LIST_LIMIT)->values()->all(),
        ];
    }

    private function staff(int $companyId): Builder
    {
        return Employee::staff()->where('employees.company_id', $companyId)->where('employees.status', '!=', 'rejected');
    }

    /**
     * Active staff per role.
     *
     * @return list<array{label: string, count: int, percent: float}>
     */
    private function departments(int $companyId): array
    {
        $rows = $this->staff($companyId)->where('employees.status', 'active')
            ->leftJoin('roles', 'roles.id', '=', 'employees.role_id')
            ->groupBy('roles.name')
            ->selectRaw('roles.name as label, COUNT(*) as total')
            ->get();
        $total = (int) $rows->sum('total');

        return $rows->map(fn (object $row): array => [
            'label' => $row->label ?: 'No role',
            'count' => (int) $row->total,
            'percent' => $this->percent((int) $row->total, $total),
        ])->sortByDesc('count')->values()->all();
    }

    /**
     * The month's payroll lines: the generated run, or the live preview before it is generated.
     *
     * @return Collection<int, array<string, float|int>>
     */
    private function payrollLines(int $companyId, CarbonImmutable $month): Collection
    {
        $run = $this->payroll->run($companyId, $month);
        if ($run) {
            return $run->items()->get()->map(fn ($item): array => $item->only(['employee_id', 'base_salary', 'allowance', 'commission', 'staff_fund', 'salary_advance', 'deduction', 'negligence', 'loan_restoration', 'take_home']));
        }

        return $this->payroll->preview($companyId, $month);
    }

    /**
     * Net salary of the payroll runs of the last six months; the selected month uses its run or the live preview.
     *
     * @return list<array{month: string, label: string, net: float}>
     */
    private function payrollTrend(int $companyId, CarbonImmutable $month, float $current): array
    {
        $from = $month->startOfMonth()->subMonthsNoOverflow(self::TREND_MONTHS - 1);
        $runs = PayrollRun::where('company_id', $companyId)
            ->whereBetween('period', [$from->toDateString(), $month->endOfMonth()->toDateString()])
            ->get()
            ->keyBy(fn (PayrollRun $run): string => $run->period->format('Y-m'));

        return collect(range(0, self::TREND_MONTHS - 1))->map(function (int $offset) use ($from, $runs, $month, $current): array {
            $at = $from->addMonthsNoOverflow($offset);
            $key = $at->format('Y-m');

            return [
                'month' => $key,
                'label' => $at->format('M'),
                'net' => $key === $month->format('Y-m') ? round($current, 2) : round((float) ($runs->get($key)?->total_net ?? 0), 2),
            ];
        })->all();
    }

    /**
     * Requests made in the month, per type (any status).
     *
     * @return list<array{key: string, label: string, count: int, percent: float}>
     */
    private function requestsByType(int $companyId, CarbonImmutable $month): array
    {
        $in = fn (string $model): int => $model::where('company_id', $companyId)->whereBetween('created_at', [$month->startOfMonth(), $month->endOfMonth()])->count();
        $counts = [
            'staff_loan' => ['Staff Loan', $in(StaffLoan::class)],
            'salary_advance' => ['Salary Advance', $in(StaffSalaryAdvance::class)],
            'allowance' => ['Allowance', $in(StaffAllowance::class)],
            'benefit_claim' => ['Benefits Claim', $in(StaffFundWithdrawal::class)],
            'leave' => ['Leave Request', $in(Leave::class)],
            'other' => ['Other', $in(SalaryChangeRequest::class)],
        ];
        $total = array_sum(array_column($counts, 1));

        return collect($counts)->map(fn (array $row, string $key): array => [
            'key' => $key,
            'label' => $row[0],
            'count' => $row[1],
            'percent' => $this->percent($row[1], $total),
        ])->values()->all();
    }

    /**
     * Per month: staff fund contributions deducted in payroll, claims paid and claims made that are still open.
     *
     * @return list<array{month: string, label: string, contributions: float, benefits_paid: float, pending_claims: float}>
     */
    private function staffFundTrend(int $companyId, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth()->subMonthsNoOverflow(self::TREND_MONTHS - 1);
        $to = $month->endOfMonth();
        $contributions = DB::table('payroll_items')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')
            ->where('payroll_runs.company_id', $companyId)
            ->whereBetween('payroll_runs.period', [$from->toDateString(), $to->toDateString()])
            ->get(['payroll_runs.period', 'payroll_items.staff_fund'])
            ->groupBy(fn (object $row): string => substr((string) $row->period, 0, 7))
            ->map(fn (Collection $rows): float => (float) $rows->sum('staff_fund'));
        $claims = StaffFundWithdrawal::where('company_id', $companyId)
            ->where(fn ($query) => $query->whereBetween('paid_at', [$from, $to])->orWhereBetween('created_at', [$from, $to]))
            ->get(['amount', 'status', 'paid_at', 'created_at']);

        return collect(range(0, self::TREND_MONTHS - 1))->map(function (int $offset) use ($from, $contributions, $claims): array {
            $at = $from->addMonthsNoOverflow($offset);
            $key = $at->format('Y-m');

            return [
                'month' => $key,
                'label' => $at->format('M'),
                'contributions' => round((float) $contributions->get($key, 0), 2),
                'benefits_paid' => round((float) $claims->filter(fn (StaffFundWithdrawal $claim): bool => $claim->status === StaffFundWithdrawal::STATUS_PAID && $claim->paid_at?->format('Y-m') === $key)->sum('amount'), 2),
                'pending_claims' => round((float) $claims->filter(fn (StaffFundWithdrawal $claim): bool => in_array($claim->status, StaffFundWithdrawal::OPEN_STATUSES, true) && $claim->created_at?->format('Y-m') === $key)->sum('amount'), 2),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, array<string, float|int>>  $lines
     * @return list<array{label: string, amount: float, employees: int}>
     */
    private function payrollSummary(Collection $lines): array
    {
        $row = fn (string $label, callable $value): array => [
            'label' => $label,
            'amount' => round((float) $lines->sum($value), 2),
            'employees' => $lines->filter(fn (array $line): bool => $value($line) > 0)->count(),
        ];

        return [
            $row('Basic Salary', fn (array $line): float => (float) $line['base_salary']),
            $row('Allowances', fn (array $line): float => (float) $line['allowance']),
            $row('Staff Fund Contribution (Deduction)', fn (array $line): float => (float) $line['staff_fund']),
            $row('Staff Loan Deductions', fn (array $line): float => (float) $line['loan_restoration']),
            $row('Salary Advance Deductions', fn (array $line): float => (float) $line['salary_advance']),
            $row('Other Deductions', fn (array $line): float => (float) $line['deduction'] + (float) $line['negligence']),
            $row('Net Salary', fn (array $line): float => (float) $line['take_home']),
        ];
    }

    /**
     * Every request waiting for a decision, newest first.
     *
     * @return Collection<int, array{type: string, type_label: string, staff: string|null, detail: string|null, amount: float, date: string, status: string, link: string}>
     */
    private function pendingRequests(int $companyId): Collection
    {
        $row = fn (string $type, string $label, $model, ?string $detail, float $amount, string $status, string $link): array => [
            'id' => "{$type}-{$model->id}",
            'type' => $type,
            'type_label' => $label,
            'staff' => $model->employee?->full_name,
            'detail' => $detail,
            'amount' => round($amount, 2),
            'date' => $model->created_at?->toDateString(),
            'status' => $status,
            'link' => $link,
        ];
        $credit = fn (string $status): string => $status === StaffCreditStatus::Submitted->value ? 'Pending' : 'Under Review';
        $statusOf = fn ($model): string => $model->status instanceof StaffCreditStatus ? $model->status->value : (string) $model->status;

        return collect()
            ->concat(StaffLoan::where('company_id', $companyId)->whereIn('status', StaffCreditStatus::awaitingDisbursement())->with('employee')->get()
                ->map(fn (StaffLoan $loan): array => $row('staff_loan', 'Staff Loan', $loan, $loan->reason, ((float) $loan->amount_approved ?: (float) $loan->amount_applied), $credit($statusOf($loan)), '/hrm/staff-loans')))
            ->concat(StaffSalaryAdvance::where('company_id', $companyId)->whereIn('status', StaffCreditStatus::awaitingDisbursement())->with('employee')->get()
                ->map(fn (StaffSalaryAdvance $advance): array => $row('salary_advance', 'Salary Advance', $advance, null, (float) $advance->amount, $credit($statusOf($advance)), '/hrm/salary-advances')))
            ->concat(StaffAllowance::where('company_id', $companyId)->where('status', StaffAllowance::STATUS_PENDING)->with('employee')->get()
                ->map(fn (StaffAllowance $allowance): array => $row('allowance', 'Allowance', $allowance, ucfirst((string) $allowance->reason), (float) $allowance->amount, 'Pending', '/hrm/allowances')))
            ->concat(StaffFundWithdrawal::where('company_id', $companyId)->whereIn('status', StaffFundWithdrawal::OPEN_STATUSES)->with('employee')->get()
                ->map(fn (StaffFundWithdrawal $claim): array => $row('benefit_claim', 'Benefits Claim', $claim, $claim->reason, (float) $claim->amount, $claim->status === StaffFundWithdrawal::STATUS_PREPARED ? 'Pending' : 'Under Review', '/hrm/staff-fund')))
            ->concat(Leave::where('company_id', $companyId)->where('status', 'pending')->with('employee')->get()
                ->map(fn (Leave $leave): array => $row('leave', 'Leave Request', $leave, $leave->remarks, 0.0, 'Pending', '/hrm/leave')))
            ->concat(SalaryChangeRequest::where('company_id', $companyId)->where('status', SalaryChangeRequest::STATUS_SUBMITTED)->with('employee')->get()
                ->map(fn (SalaryChangeRequest $change): array => $row('other', 'Salary Change', $change, $change->reason, 0.0, 'Pending', '/hrm/salary-changes')));
    }

    private function change(int $now, int $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    private function percent(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }
}
