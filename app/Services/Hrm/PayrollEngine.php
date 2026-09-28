<?php

namespace App\Services\Hrm;

use App\Enums\Account;
use App\Enums\SalaryType;
use App\Enums\StaffCreditStatus;
use App\Models\Branch;
use App\Models\CommissionAllocation;
use App\Models\Company;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\NegligenceRecovery;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\SalaryPayment;
use App\Models\StaffAllowance;
use App\Models\StaffDeduction;
use App\Models\StaffLoan;
use App\Models\StaffSalaryAdvance;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly payroll engine (STAFF COMMISSION §4, §10–11, §16).
 *
 *   Gross     = Base Salary + Allowance
 *   Take Home = Gross − (Staff Fund + Salary Advance + Deduction + Loan Restoration)
 *
 * Spec §21 / §22 (commission payment flow): commission is NOT a payroll column any more — each employee's commission of a
 * closed period is requested by HR, approved and paid by Finance on its own date ({@see CommissionPayments}), and negligence is
 * recovered from that payment. LEGACY: runs generated before that change carry commission (and its negligence recovery) in
 * their lines; their allocations are linked to the run (status `payroll`) and approve/pay still handle them exactly as before.
 *
 * Workflow: HR generates and approves (payroll.approve) → Finance pays (payroll.pay).
 *
 * Approval (salary recognition):  Dr Salary / Allowance Expense, Dr Commission Payable (allocated commission, D1)
 *                                  or Commission Expense (legacy)   Cr Staff Payable
 *                                  Dr Salary Expense   Cr Staff Fund Obligation (company contribution, spec §26)
 * Payment:                          Dr Staff Payable   Cr Staff Fund, Staff Advance, Staff Loan, paying account
 *                                  negligence (spec §23): Dr Staff Payable   Cr Write-off Expense;  Dr Principal A/C   Cr paying account
 *
 * Spec §20 — payroll is an expense of its PERIOD, not of the payment date: the recognition journal (salary, allowance,
 * commission, company contribution) and the salary-expense part of the payment (other deductions) are dated inside the payroll
 * month — today while the month is running, the month's last day once it is over. Cash movements (the payment journal) and
 * `paid_at` / `paid_on` keep the real payment date. When the payroll month is already CLOSED in the ledger nothing can be
 * posted into it any more (closed history is never rewritten): the expense is then recognised on the approval date in the
 * open period and the run records why in `expense_period_note`, which the payroll sheet shows — it is never silent.
 *
 * Spec §24 — only Finance-approved allowances are paid: recurring approved (legacy `active`) allowances every month, and an
 * approved allowance of this or an earlier payroll period once — generation reserves it for the run, payment marks it paid.
 *
 * Spec §26, as ruled by the product owner (2026-09-17): SALARY EXPENSE is the full basic salary and nothing more. The
 * staff fund share (staff_fund_percent, 20 %) is withheld from it and goes into the STAFF FUND A/C as real cash — basic
 * 1,000,000: expense 1,000,000, staff receives 800,000, fund receives 200,000. There is no separate company contribution.
 *
 * Spec §23 — negligence is never deducted from salary. It is recovered from commission payments ({@see CommissionPayments});
 * only legacy runs that still carry commission recover it from the commission of their lines ({@see NegligenceDeductions}).
 *
 * Branch staff and zone managers are paid from the INTEREST A/C of their branch, HQ staff from the
 * COMPANY ACCOUNT (Documents: "Matumizi na Mishahara inatoka kwenye interest account", "HQ matumizi yake
 * hayatoki kwenye interest ya branches").
 */
class PayrollEngine
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly CommissionEngine $commission,
        private readonly StaffCredit $credit,
        private readonly NegligenceDeductions $negligence,
    ) {}

    public function run(int $companyId, CarbonImmutable $month): ?PayrollRun
    {
        return PayrollRun::where('company_id', $companyId)->whereDate('period', $month->startOfMonth()->toDateString())->first();
    }

    /**
     * Computed lines for every active employee with salary information.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function preview(int $companyId, CarbonImmutable $month): Collection
    {
        $settings = HrmSetting::forCompany($companyId);

        $employees = Employee::staff()->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereHas('salaryInfo', fn ($query) => $query->where('salary', '>', 0))
            ->with([
                'salaryInfo',
                'branch',
                'salaryAdvances' => fn ($query) => $query->whereIn('status', StaffCreditStatus::recovering())->orderBy('id'),
                'allowances' => fn ($query) => $query->payableIn($month)->orderBy('id'),
                'deductions' => fn ($query) => $query->where('status', 'active'),
                'staffLoans' => fn ($query) => $query->whereIn('status', StaffCreditStatus::recovering())->withSum('payments', 'amount')->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();

        return $employees->map(fn (Employee $employee): array => $this->line(
            $employee,
            (float) $settings->staff_fund_percent,
            0.0,
            0.0,
        ));
    }

    /**
     * STEP 1 "POST /payroll/generate": (re)build the draft payroll for a month. Commission is not part of it (spec §21 / §22:
     * it is paid through {@see CommissionPayments}); regenerating a draft that still carried commission (legacy) releases its
     * allocations to the commission payment flow.
     */
    public function generate(int $companyId, CarbonImmutable $month, Employee $preparer): PayrollRun
    {
        $existing = $this->run($companyId, $month);
        if ($existing !== null && $existing->status !== PayrollRun::STATUS_DRAFT) {
            throw ValidationException::withMessages(['period' => 'Payroll for this month is already approved and can not be changed']);
        }

        return DB::transaction(function () use ($companyId, $month, $preparer, $existing): PayrollRun {
            $period = $this->commission->closedPeriod($companyId, $month);

            $run = $existing ?? PayrollRun::create(['company_id' => $companyId, 'period' => $month->startOfMonth()->toDateString(), 'status' => PayrollRun::STATUS_DRAFT]);
            $run->items()->delete();
            StaffAllowance::where('payroll_run_id', $run->id)->where('status', StaffAllowance::STATUS_APPROVED)->update(['payroll_run_id' => null]);

            $lines = $this->preview($companyId, $month);
            foreach ($lines as $line) {
                $run->items()->create(collect($line)->except(['employee', 'branch', 'allowance_ids', 'negligence_outstanding', 'net_commission'])->all());
                StaffAllowance::whereIn('id', $line['allowance_ids'])->where('status', StaffAllowance::STATUS_APPROVED)->update(['payroll_run_id' => $run->id]);
            }

            CommissionAllocation::where('payroll_run_id', $run->id)->where('payment_status', CommissionAllocation::STATUS_PAYROLL)
                ->update(['payroll_run_id' => null, 'payment_status' => CommissionAllocation::STATUS_CALCULATED]);

            $run->update([
                'prepared_by' => $preparer->id,
                'commission_status' => $period === null ? 'period_not_closed' : 'calculated',
                'total_gross' => round((float) $lines->sum('gross'), 2),
                'total_deductions' => round((float) $lines->sum('total_deductions'), 2),
                'total_net' => round((float) $lines->sum('take_home'), 2),
            ]);

            return $run->fresh();
        });
    }

    /**
     * STEP 3 salary recognition: Dr expenses / Cr Staff Payable per employee. Values are frozen afterwards.
     *
     * Commission that was allocated from profit by a journal (user decision D1) is already a liability: Dr COMMISSION PAYABLE
     * (the exact branch/employee accounts the allocation credited). Commission without an allocation journal (legacy, e.g.
     * June 2026) keeps Dr COMMISSION EXPENSE.
     */
    public function approve(PayrollRun $run, Employee $approver): void
    {
        if ($run->status !== PayrollRun::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Payroll is not a draft']);
        }
        if (! $run->items()->exists()) {
            throw ValidationException::withMessages(['status' => 'Payroll has no staff to approve']);
        }
        if ((float) $run->items()->sum('commission') > 0 && ! CommissionAllocation::where('payroll_run_id', $run->id)->exists()) {
            throw ValidationException::withMessages(['status' => 'This draft payroll still carries commission, which is now paid through the commission payment flow. Generate the payroll again before approving it.']);
        }

        DB::transaction(function () use ($run, $approver): void {
            $run->load('items.employee');
            $payable = $this->allocatedCommissionPayable($run);
            ['date' => $expenseDate, 'note' => $expenseNote] = $this->expenseDate($run);

            foreach ($run->items as $item) {
                $branchId = $this->payingAccount($item)['branch'];
                $commission = round((float) $item->commission, 2);
                $lines = [
                    ['account' => Account::SalaryExpense, 'branch' => $branchId, 'debit' => (float) $item->base_salary],
                ];
                foreach ($payable[$item->employee_id] ?? [] as $allocationBranchId => $credited) {
                    $portion = round(min($credited, $commission), 2);
                    if ($portion <= 0) {
                        continue;
                    }
                    $lines[] = ['account' => Account::CommissionPayable, 'branch' => $allocationBranchId, 'employee' => $item->employee_id, 'debit' => $portion];
                    $commission = round($commission - $portion, 2);
                }
                $lines[] = ['account' => Account::CommissionExpense, 'branch' => $branchId, 'debit' => $commission];
                $lines[] = ['account' => Account::AllowanceExpense, 'branch' => $branchId, 'debit' => (float) $item->allowance];
                $lines[] = ['account' => Account::StaffPayable, 'employee' => $item->employee_id, 'credit' => (float) $item->gross];

                $this->ledger->journal($run->company_id, 'Salary recognition '.$run->period->format('F Y').' - '.$item->employee->full_name, $lines, $run, $expenseDate, $branchId, $approver);
            }

            $run->update([
                'status' => PayrollRun::STATUS_APPROVED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'expense_date' => $expenseDate->toDateString(),
                'expense_period_note' => $expenseNote,
            ]);
        });
    }

    /**
     * STEP 4 payment by Finance. Deductions are withheld automatically: staff fund contribution,
     * salary advance and staff loan recoveries go back to the Staff Fund A/C (or the advance's source
     * account); other deductions reduce the salary expense (inferred). The take home leaves the paying account.
     */
    public function pay(PayrollRun $run, Employee $payer): int
    {
        if ($run->status !== PayrollRun::STATUS_APPROVED) {
            throw ValidationException::withMessages(['status' => 'Payroll must be approved by HR before payment']);
        }

        return DB::transaction(function () use ($run, $payer): int {
            Company::whereKey($run->company_id)->lockForUpdate()->firstOrFail();
            if (PayrollRun::whereKey($run->id)->lockForUpdate()->value('status') !== PayrollRun::STATUS_APPROVED) {
                throw ValidationException::withMessages(['status' => 'Payroll must be approved by HR before payment']);
            }

            $run->load(['items.employee.salaryInfo']);
            $before = $this->payingBalances($run);
            $totalNet = 0.0;

            foreach ($run->items as $item) {
                $totalNet += $this->payItem($run, $item, $payer);
            }

            $this->assertFunded($run, $before);

            StaffAllowance::where('payroll_run_id', $run->id)->where('status', StaffAllowance::STATUS_APPROVED)->update(['status' => StaffAllowance::STATUS_PAID, 'paid_at' => now()]);

            $run->update([
                'status' => PayrollRun::STATUS_PAID,
                'paid_by' => $payer->id,
                'paid_at' => now(),
                'total_net' => round($totalNet, 2),
                'total_deductions' => round((float) $run->items()->sum('total_deductions'), 2),
            ]);

            return $run->items->count();
        });
    }

    /** OPERATION INCOME on the HQ Account List: interest, loan fee and penalty of every branch plus HQ's own. */
    private const OPERATION_INCOME = [Account::Interest, Account::HqInterest, Account::LoanFee, Account::HqLoanFee, Account::Penalty, Account::HqPenalty];

    /**
     * Balance of every account the run pays from (branch INTEREST A/C, COMPANY A/C) and of the OPERATION INCOME pool,
     * taken before anything is posted.
     *
     * @return array<string, array{account: Account, branch: int|null, balance: float}>
     */
    private function payingBalances(PayrollRun $run): array
    {
        $balances = [];
        foreach ($run->items as $item) {
            $paying = $this->payingAccount($item);
            $key = $paying['account']->value.':'.($paying['branch'] ?? 'hq');
            $balances[$key] ??= $paying + ['balance' => $this->ledger->balance($run->company_id, $paying['account'], $paying['branch'])];
        }
        $balances['operation_income'] = ['account' => Account::Interest, 'branch' => null, 'balance' => $this->operationIncome($run->company_id)];

        return $balances;
    }

    private function operationIncome(int $companyId): float
    {
        return round(array_sum(array_map(fn (Account $account): float => $this->ledger->balance($companyId, $account, allBranches: true), self::OPERATION_INCOME)), 2);
    }

    /**
     * A payroll is paid in full or not at all: when any paying account, or OPERATION INCOME as a whole, would end below zero
     * the payment is refused and the transaction rolls every salary back.
     *
     * @param  array<string, array{account: Account, branch: int|null, balance: float}>  $before
     *
     * @throws ValidationException
     */
    private function assertFunded(PayrollRun $run, array $before): void
    {
        $errors = [];
        foreach ($before as $key => $row) {
            $after = $key === 'operation_income'
                ? $this->operationIncome($run->company_id)
                : $this->ledger->balance($run->company_id, $row['account'], $row['branch']);
            if ($after > -0.001) {
                continue;
            }

            $label = $key === 'operation_income'
                ? 'Operation Income'
                : $row['account']->label().($row['branch'] !== null ? ' ('.(Branch::find($row['branch'])?->name ?? 'branch '.$row['branch']).')' : '');
            $errors[] = 'Insufficient balance in '.$label.': available '.number_format(max(0, $row['balance']), 2)
                .', payroll needs '.number_format(round($row['balance'] - $after, 2), 2).'.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['status' => $errors]);
        }
    }

    /**
     * Spec §20: the date a payroll's expense is recognised on — inside the payroll month (today while it runs, its first day
     * before it starts, its last day once it is over). When that month is already closed in the ledger the expense can no
     * longer reach it: today's date is used and the note says why.
     *
     * @return array{date: CarbonImmutable, note: string|null}
     */
    public function expenseDate(PayrollRun $run): array
    {
        $start = CarbonImmutable::parse($run->period->toDateString())->startOfMonth();
        $end = $start->endOfMonth()->startOfDay();
        $today = CarbonImmutable::today();
        $date = $today->lessThan($start) ? $start : ($today->greaterThan($end) ? $end : $today);

        if ($this->isPeriodOpen((int) $run->company_id, $date)) {
            return ['date' => $date, 'note' => null];
        }

        return [
            'date' => $today,
            'note' => "The {$start->format('F Y')} accounting period was already closed, so this payroll's expense could not be dated inside it and was recognised on {$today->toDateString()}.",
        ];
    }

    private function isPeriodOpen(int $companyId, CarbonInterface $date): bool
    {
        try {
            $this->ledger->assertPeriodOpen($companyId, $date);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * Commission credited to COMMISSION PAYABLE by the standing allocation journals of the run's closed month, per employee
     * and allocation branch. Empty for a month without journal-posted allocations (legacy expense recognition).
     *
     * @return array<int, array<int, float>> employee id → branch id → amount
     */
    private function allocatedCommissionPayable(PayrollRun $run): array
    {
        $period = $this->commission->closedPeriod((int) $run->company_id, CarbonImmutable::parse($run->period->toDateString()));
        if ($period === null) {
            return [];
        }

        $payable = [];
        foreach ($this->commission->allocationJournals($period) as $entry) {
            foreach ($entry->lines as $line) {
                if ($line->account?->key === Account::CommissionPayable && $line->account->employee_id !== null) {
                    $employeeId = (int) $line->account->employee_id;
                    $branchId = (int) $line->account->branch_id;
                    $payable[$employeeId][$branchId] = round(($payable[$employeeId][$branchId] ?? 0) + (float) $line->credit - (float) $line->debit, 2);
                }
            }
        }

        return $payable;
    }

    /**
     * @return array{account: Account, branch: int|null}
     */
    public function payingAccount(PayrollItem|array $item): array
    {
        $type = is_array($item) ? $item['salary_type'] : $item->salary_type;
        $branchId = is_array($item) ? $item['branch_id'] : $item->branch_id;

        return $type === SalaryType::Hq->value || $branchId === null
            ? ['account' => Account::Company, 'branch' => null]
            : ['account' => Account::Interest, 'branch' => (int) $branchId];
    }

    private function payItem(PayrollRun $run, PayrollItem $item, Employee $payer): float
    {
        $employee = $item->employee;
        $paying = $this->payingAccount($item);
        $gross = (float) $item->gross;

        $advances = StaffSalaryAdvance::where('employee_id', $employee->id)->whereIn('status', StaffCreditStatus::recovering())->orderBy('id')->get();
        $loans = StaffLoan::where('employee_id', $employee->id)->whereIn('status', StaffCreditStatus::recovering())->withSum('payments', 'amount')->orderBy('id')->get();
        $deductions = StaffDeduction::where('employee_id', $employee->id)->where('status', 'active')->orderBy('id')->get();

        $recoveries = $this->negligence->recover($employee->id, (float) $item->negligence, (float) $item->commission, $run->period, $run);
        $negligenceTaken = round((float) $recoveries->sum('amount'), 2);

        $lines = [['account' => Account::StaffPayable, 'employee' => $employee->id, 'debit' => $gross]];
        $toFundCash = (float) $item->staff_fund;
        $lines[] = ['account' => Account::WriteOffExpense, 'branch' => $item->branch_id, 'credit' => $negligenceTaken];
        $lines[] = ['account' => Account::Principal, 'debit' => $negligenceTaken];
        $lines[] = ['account' => Account::StaffFund, 'employee' => $employee->id, 'credit' => (float) $item->staff_fund];

        $advanceLeft = min((float) $item->salary_advance, (float) $advances->sum(fn (StaffSalaryAdvance $advance): float => $advance->outstandingAmount()));
        $advanceTaken = 0.0;
        foreach ($advances as $advance) {
            $portion = min($advanceLeft, $advance->outstandingAmount());
            if ($portion <= 0) {
                continue;
            }
            $advanceLeft = round($advanceLeft - $portion, 2);
            $advanceTaken += $portion;
            $recovered = round((float) $advance->recovered_amount + $portion, 2);
            $advance->update($this->credit->advanceRecoveryValues($advance, $recovered));

            $lines[] = ['account' => Account::StaffAdvanceReceivable, 'employee' => $employee->id, 'credit' => $portion];
            if ($advance->source_account === Account::Company->value) {
                $lines[] = ['account' => Account::Company, 'debit' => $portion];
                $lines[] = $paying + ['credit' => $portion];
            } else {
                $toFundCash += $portion;
            }
        }

        $loanLeft = (float) $item->loan_restoration;
        $loanTaken = 0.0;
        foreach ($loans as $loan) {
            $portion = min($loanLeft, (float) $loan->restoration, $loan->remainingAmount());
            if ($portion <= 0) {
                continue;
            }
            $loanLeft = round($loanLeft - $portion, 2);
            $loanTaken += $portion;
            [$principal, $interest] = $this->credit->splitLoanPayment($loan, $portion);
            $this->credit->recordLoanPayment($loan, $portion);

            $lines[] = ['account' => Account::StaffLoanReceivable, 'employee' => $employee->id, 'credit' => $principal];
            $lines[] = ['account' => Account::StaffFund, 'credit' => $interest];
            $toFundCash += $portion;
        }

        $deductionLeft = (float) $item->deduction;
        $deductionTaken = 0.0;
        foreach ($deductions as $deduction) {
            $portion = min($deductionLeft, $this->deductionInstalment($deduction));
            if ($portion <= 0) {
                continue;
            }
            $deductionLeft = round($deductionLeft - $portion, 2);
            $deductionTaken += $portion;
            $paid = round((float) $deduction->paid_amount + $portion, 2);
            $deduction->update(['paid_amount' => $paid, 'status' => $paid >= (float) $deduction->amount ? 'done' : 'active']);
        }
        $lines[0]['debit'] = round($gross - $deductionTaken, 2);

        $totalDeductions = round($negligenceTaken + (float) $item->staff_fund + $advanceTaken + $loanTaken + $deductionTaken, 2);
        $takeHome = round($gross - $totalDeductions, 2);

        $lines[] = $paying + ['credit' => $takeHome + $toFundCash + $negligenceTaken];
        $lines[] = ['account' => Account::StaffFundCash, 'debit' => $toFundCash];

        $payment = SalaryPayment::create([
            'company_id' => $run->company_id,
            'employee_id' => $employee->id,
            'payroll_run_id' => $run->id,
            'branch_id' => $item->branch_id,
            'salary_type' => $item->salary_type,
            'salary' => $item->base_salary,
            'commission' => $item->commission,
            'salary_advance' => $advanceTaken,
            'allowance' => $item->allowance,
            'staff_fund' => $item->staff_fund,
            'deduction' => $deductionTaken,
            'negligence' => $negligenceTaken,
            'loan_restoration' => $loanTaken,
            'take_home' => $takeHome,
            'phone' => $employee->phone,
            'account_name' => $item->account_name,
            'account_number' => $item->account_number,
            'paid_from_account' => $paying['account']->label(),
            'paid_on' => now(),
        ]);

        $entry = $this->ledger->journal($run->company_id, 'Salary payment '.$run->period->format('F Y').' - '.$employee->full_name, $lines, $payment, null, $paying['branch'], $payer);
        NegligenceRecovery::whereIn('id', $recoveries->pluck('id'))->update(['salary_payment_id' => $payment->id, 'journal_entry_id' => $entry->id]);

        if ($deductionTaken > 0) {
            // Spec §20: other deductions reduce the payroll's salary expense, so they belong to the payroll period as well.
            $this->ledger->journal($run->company_id, 'Salary deductions '.$run->period->format('F Y').' - '.$employee->full_name, [
                ['account' => Account::StaffPayable, 'employee' => $employee->id, 'debit' => $deductionTaken],
                ['account' => Account::SalaryExpense, 'branch' => $paying['branch'], 'credit' => $deductionTaken],
            ], $payment, $this->expenseDate($run)['date'], $paying['branch'], $payer);
        }

        $item->update([
            'negligence' => $negligenceTaken,
            'salary_advance' => $advanceTaken,
            'loan_restoration' => $loanTaken,
            'deduction' => $deductionTaken,
            'total_deductions' => $totalDeductions,
            'take_home' => $takeHome,
            'salary_payment_id' => $payment->id,
        ]);

        return $takeHome;
    }

    /**
     * @return array<string, mixed>
     */
    private function line(Employee $employee, float $staffFundPercent, float $commission, float $negligenceOutstanding): array
    {
        $info = $employee->salaryInfo;
        $type = $info->salary_type ?: SalaryType::Branch->value;
        $base = (float) $info->salary;
        $commission = $type === SalaryType::Hq->value ? 0.0 : round($commission, 2);
        $allowance = round((float) $employee->allowances->sum('amount'), 2);
        $gross = round($base + $commission + $allowance, 2);

        $negligence = round(max(0, min($negligenceOutstanding, $commission)), 2);
        $remaining = round($gross - $negligence, 2);
        $take = function (float $amount) use (&$remaining): float {
            $taken = round(max(0, min($amount, $remaining)), 2);
            $remaining = round($remaining - $taken, 2);

            return $taken;
        };

        $staffFund = $take(round($base * $staffFundPercent / 100, 2));
        $advance = $take((float) $employee->salaryAdvances->sum(fn (StaffSalaryAdvance $item): float => $item->outstandingAmount()));
        $deduction = $take((float) $employee->deductions->sum(fn (StaffDeduction $item): float => $this->deductionInstalment($item)));
        $restoration = $take((float) $employee->staffLoans->sum(fn (StaffLoan $loan): float => min((float) $loan->restoration, $loan->remainingAmount())));
        $totalDeductions = round($negligence + $staffFund + $advance + $deduction + $restoration, 2);

        return [
            'employee_id' => $employee->id,
            'employee' => $employee->full_name,
            'branch_id' => $employee->branch_id,
            'branch' => $employee->branch?->name,
            'salary_type' => $type,
            'base_salary' => $base,
            'commission' => $commission,
            'allowance' => $allowance,
            'gross' => $gross,
            'staff_fund' => $staffFund,
            'salary_advance' => $advance,
            'deduction' => $deduction,
            'negligence' => $negligence,
            'negligence_outstanding' => round($negligenceOutstanding, 2),
            'net_commission' => round($commission - $negligence, 2),
            'loan_restoration' => $restoration,
            'total_deductions' => $totalDeductions,
            'take_home' => round($gross - $totalDeductions, 2),
            'phone' => $employee->phone,
            'account_name' => $info->account_name,
            'account_number' => $info->account_number,
            'payment_method' => $info->payment_method,
            'allowance_ids' => $employee->allowances->where('status', StaffAllowance::STATUS_APPROVED)->pluck('id')->values()->all(),
        ];
    }

    private function deductionInstalment(StaffDeduction $deduction): float
    {
        return min((float) $deduction->instalment_amount, max(0, (float) $deduction->amount - (float) $deduction->paid_amount));
    }
}
