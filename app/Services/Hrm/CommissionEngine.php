<?php

namespace App\Services\Hrm;

use App\Enums\Account;
use App\Enums\SalaryType;
use App\Enums\TransactionType;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\BranchPeriodResult;
use App\Models\CommissionAllocation;
use App\Models\DividendDeclaration;
use App\Models\DividendDeclarationRequest;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Commission engine (STAFF COMMISSION §5–9, handwritten note "COMMISSION"; Fund Flow Specification §13, §34, §36F).
 *
 *  1. Distributable profit per branch is read from branch_period_results, written by the
 *     accounting month-end close (profit − loss carry forward − 2% HQ hold).
 *  2. Branch commission pool = configured % (10) × COMMISSION BASE (0 when the branch is blocked). Spec §15/§59: the commission
 *     base is distributable profit − the offset settled in the period (`branch_period_results.offset_amount`, stored by the
 *     month-end close), because an offset is not cash collected and never generates commission. The offset is not lost: it
 *     stays in profit and is added back before dividend/reinvestment (the distribution base is distributable − commission).
 *     Periods calculated before this rule keep their stored pools; their commission base is derived from the stored pool.
 *  3. Zone manager share (C4): the configured override % (5) OF THAT BRANCH POOL is ALWAYS carved out of it. The eligible
 *     zone manager(s) of the branch's zone receive it (several zone managers of one zone share it by salary); when the zone
 *     has no eligible zone manager (or the branch has no zone) that 5 % is returned to profit.
 *  4. Branch staff share the remaining 95 % — never more, even without a zone manager — by salary:
 *     (staff base salary ÷ total branch commission-eligible salary) × staff pool; the last line takes the cent remainder.
 *  5. A branch with no eligible staff allocates NOTHING: the ENTIRE pool (including the zone manager's 5 %) is returned to
 *     profit. A branch with zero (or negative) distributable profit has no pool and allocates nothing.
 *
 * Commission is a PROFIT ALLOCATION, not an expense (user decision D1). Calculating a closed month posts, per branch, a
 * journal dated today: Dr PROFIT ACCOUNT (branch) / Cr COMMISSION PAYABLE (branch, employee) — source: the branch period
 * result; every allocation row links its journal. Recalculating (while unlocked) reverses the previous allocation journals
 * and posts new ones. The commission is then paid through its own flow (spec §21 / §22, {@see CommissionPayments}): HR finalises
 * and requests payment, Finance approves and pays — Dr COMMISSION PAYABLE / Cr paying account, less recovered negligence.
 * LEGACY: allocations carried by a payroll run before that flow were moved to STAFF PAYABLE by the payroll approval; allocations
 * without a journal (June 2026) keep their stored figures and are recognised as COMMISSION EXPENSE.
 *
 * Locks: an approved payroll (legacy), commission finalised for payment, a dividend declaration request of the month awaiting approval, or a dividend declaration (C1:
 * dividends can only be declared after commission is calculated, so the declaration base subtracts the calculated commission and
 * commission can no longer change). Legacy declarations made without calculated commission keep locking the month as booked.
 *
 * Returned to profit (no eligible zone manager: the 5 %; no eligible staff: the whole pool) is NOT allocated: no allocation row,
 * no journal, not an expense, suspense or reserve — it stays in the branch PROFIT ACCOUNT and therefore in the dividend base.
 * Report rows show both reasons: `returned_no_zone_manager_amount` and `returned_no_staff_amount`.
 *
 * HQ staff (salary type "hq") never receive commission.
 *
 * Profit status of a branch: net profit > 0 → eligible (pool from the distributable profit); net profit = 0 →
 * not eligible, "No distributable profit" (not a loss, nothing carried forward); net profit < 0 or a loss still
 * carried forward → "Loss must be recovered before commission".
 */
class CommissionEngine
{
    public const PROFIT_ELIGIBLE = 'eligible';

    public const PROFIT_NONE = 'no_distributable_profit';

    public const PROFIT_LOSS = 'loss';

    public const REASON_LOSS = 'Loss must be recovered before commission';

    public const REASON_NO_PROFIT = 'No distributable profit';

    public const LOCKED_MESSAGE = 'Commission for this period is already in an approved payroll and cannot be changed.';

    public const LOCKED_BY_DIVIDEND_MESSAGE = 'Dividends for this period have already been declared from the profit after commission; commission cannot be recalculated.';

    public const LOCKED_BY_PAYMENT_MESSAGE = 'Commission for this period has been finalised for payment and cannot be recalculated.';

    public const LOCKED_BY_DIVIDEND_REQUEST_MESSAGE = 'A dividend declaration for this period is awaiting approval; reject it before recalculating commission.';

    /** LEGACY only: a declaration booked before C1 without calculated commission distributed the full distributable profit. */
    public const LEGACY_DECLARED_WITHOUT_COMMISSION_MESSAGE = 'Dividends for :month were declared (legacy) without calculated commission; commission can no longer be calculated for this period.';

    public const RULE_PROFIT_ALLOCATION = 'profit_allocation';

    public const RULE_LEGACY = 'legacy_expense';

    public const STATUS_NOT_CALCULATED = 'NOT_CALCULATED';

    public const STATUS_ALLOCATED = 'ALLOCATED';

    public const STATUS_LOCKED_IN_PAYROLL = 'LOCKED_IN_PAYROLL';

    public const STATUS_LOCKED_BY_DIVIDEND = 'LOCKED_BY_DIVIDEND_DECLARATION';

    /** HR finalised the period's commission for payment (spec §21 / §22): awaiting request, requested, approved or paid. */
    public const STATUS_LOCKED_IN_PAYMENT = 'LOCKED_IN_COMMISSION_PAYMENT';

    public function __construct(private readonly Ledger $ledger) {}

    /**
     * The accounting period of a month when it is CLOSED by the month-end close (Accounting → Month End &
     * Profit), or null when it is still open — even if its profit has already been calculated.
     */
    public function closedPeriod(int $companyId, CarbonImmutable $month): ?AccountingPeriod
    {
        return AccountingPeriod::query()
            ->where('company_id', $companyId)
            ->whereDate('period_start', $month->startOfMonth()->toDateString())
            ->closed()
            ->first();
    }

    /**
     * Commission figures for a month: stored allocations when calculated, otherwise a preview.
     *
     * @return array<string, mixed>
     */
    public function report(int $companyId, CarbonImmutable $month): array
    {
        $settings = HrmSetting::forCompany($companyId);
        $period = $this->closedPeriod($companyId, $month);

        $base = [
            'period_closed' => $period !== null,
            'calculated' => false,
            'calculated_at' => null,
            'locked' => false,
            'can_calculate' => false,
            'calculate_blocked_reason' => $period === null ? $this->notClosedMessage($month) : null,
            'period' => $period ? ['id' => $period->id, 'period_start' => $period->period_start->toDateString(), 'period_end' => $period->period_end->toDateString(), 'status' => $period->status] : null,
            'pool_percent' => (float) $settings->commission_pool_percent,
            'zone_override_percent' => (float) $settings->zone_override_percent,
            'branches' => [],
            'zone_managers' => [],
            'summary' => $this->summary([]),
            'total_commission' => 0.0,
            'total_returned_to_profit' => 0.0,
        ];

        if ($period === null) {
            return $base + ['rule' => null, 'allocation_status' => self::STATUS_NOT_CALCULATED, 'journal_references' => []];
        }

        $stored = CommissionAllocation::where('accounting_period_id', $period->id)->with('employee')->get();
        $computed = $this->compute($period, $settings);
        $lockReason = $this->lockReason($period);
        $rule = null;
        $journals = collect();
        $base['calculated'] = $this->isCalculated($period);
        $base['locked'] = $lockReason !== null;
        $base['calculated_at'] = $period->commission_calculated_at?->toDateTimeString();

        if ($stored->isNotEmpty()) {
            $base['pool_percent'] = (float) ($stored->first()->pool_percent ?? $settings->commission_pool_percent);
            $rule = $this->isLegacy($period, $stored) ? self::RULE_LEGACY : self::RULE_PROFIT_ALLOCATION;
            $journals = $rule === self::RULE_PROFIT_ALLOCATION ? $this->allocationJournals($period) : collect();
            $computed = $rule === self::RULE_PROFIT_ALLOCATION ? $this->fromJournals($stored, $computed, $journals) : $this->fromStored($period, $stored, $computed);
        }

        $base['can_calculate'] = $lockReason === null;
        $base['calculate_blocked_reason'] = $lockReason;

        if (! $base['calculated'] && $this->declaration($period) !== null) {
            $computed = $this->legacyDeclaredWithoutCommission($computed);
        }

        return array_merge($base, $computed, [
            'summary' => $this->summary($computed['branches']),
            'rule' => $rule,
            'allocation_status' => match (true) {
                $lockReason === self::LOCKED_MESSAGE => self::STATUS_LOCKED_IN_PAYROLL,
                $lockReason === self::LOCKED_BY_PAYMENT_MESSAGE => self::STATUS_LOCKED_IN_PAYMENT,
                $lockReason === self::LOCKED_BY_DIVIDEND_REQUEST_MESSAGE => $base['calculated'] ? self::STATUS_ALLOCATED : self::STATUS_NOT_CALCULATED,
                $lockReason !== null => self::STATUS_LOCKED_BY_DIVIDEND,
                $base['calculated'] => self::STATUS_ALLOCATED,
                default => self::STATUS_NOT_CALCULATED,
            },
            'total_returned_to_profit' => round((float) collect($computed['branches'])->sum('returned_to_profit_amount'), 2),
            'total_returned_no_zone_manager' => round((float) collect($computed['branches'])->sum('returned_no_zone_manager_amount'), 2),
            'total_returned_no_staff' => round((float) collect($computed['branches'])->sum('returned_no_staff_amount'), 2),
            'journal_references' => $journals->map(fn (JournalEntry $entry): array => ['id' => $entry->id, 'reference' => $entry->reference, 'branch_id' => $entry->branch_id, 'entry_date' => $entry->entry_date->toDateString()])->values()->all(),
        ]);
    }

    /**
     * Persist the allocations of a closed month and post their profit-allocation journals (replacing an earlier, unlocked
     * calculation: its journals are reversed first). Everything happens in one transaction with the period row locked.
     *
     * @return Collection<int, CommissionAllocation>
     *
     * @throws ValidationException
     */
    public function calculate(int $companyId, CarbonImmutable $month, ?Employee $employee = null): Collection
    {
        $period = $this->closedPeriod($companyId, $month);

        if ($period === null) {
            throw ValidationException::withMessages(['period' => $this->notClosedMessage($month)]);
        }

        return DB::transaction(function () use ($period, $companyId, $employee): Collection {
            $period = AccountingPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();

            if (($reason = $this->lockReason($period)) !== null) {
                throw ValidationException::withMessages(['period' => $reason]);
            }

            $settings = HrmSetting::forCompany($companyId);
            $computed = $this->compute($period, $settings);
            $previous = CommissionAllocation::where('accounting_period_id', $period->id)->get();

            $label = $period->period_start->format('Y-m');
            $reversed = [];
            foreach ($this->allocationJournals($period) as $entry) {
                $reversed[] = $this->ledger->reverse($entry, "COMMISSION RECALCULATED {$label}")->reference;
            }
            CommissionAllocation::where('accounting_period_id', $period->id)->delete();

            $rows = collect();
            $journalByBranch = [];
            $results = BranchPeriodResult::where('accounting_period_id', $period->id)->with('branch:id,name')->get()->keyBy('branch_id');

            foreach ($computed['branches'] as $branch) {
                $lines = [];
                foreach ($branch['staff'] as $line) {
                    $lines[] = ['account' => Account::CommissionPayable, 'branch' => $branch['branch_id'], 'employee' => $line['employee_id'], 'credit' => $line['amount']];
                }
                foreach ($branch['zone_manager_shares'] as $share) {
                    $lines[] = ['account' => Account::CommissionPayable, 'branch' => $branch['branch_id'], 'employee' => $share['employee_id'], 'credit' => $share['amount']];
                }
                $allocated = round(array_sum(array_column($lines, 'credit')), 2);
                if ($allocated <= 0) {
                    continue;
                }
                $lines[] = ['account' => Account::RetainedProfit, 'branch' => $branch['branch_id'], 'debit' => $allocated];

                $journalByBranch[$branch['branch_id']] = $this->ledger->journal(
                    $period->company_id,
                    "COMMISSION ALLOCATION {$label} - {$branch['branch']}",
                    $lines,
                    $results->get($branch['branch_id']),
                    CarbonImmutable::today(),
                    $branch['branch_id'],
                    $employee,
                    TransactionType::CommissionAllocation,
                );
            }

            foreach ($computed['branches'] as $branch) {
                foreach ($branch['staff'] as $line) {
                    $rows->push(CommissionAllocation::create([
                        'company_id' => $period->company_id,
                        'accounting_period_id' => $period->id,
                        'branch_id' => $branch['branch_id'],
                        'employee_id' => $line['employee_id'],
                        'kind' => CommissionAllocation::KIND_BRANCH_STAFF,
                        'distributable_profit' => $branch['distributable_profit'],
                        'offset_amount' => $branch['offset_amount'],
                        'commission_base' => $branch['commission_base'],
                        'pool_percent' => $computed['pool_percent'],
                        'pool_amount' => $branch['pool_amount'],
                        'zone_allocation' => round($branch['pool_amount'] - $branch['staff_pool_amount'], 2),
                        'base_salary' => $line['base_salary'],
                        'total_salary' => $branch['total_salary'],
                        'share_percent' => $line['share_percent'],
                        'amount' => $line['amount'],
                        'journal_entry_id' => $line['amount'] > 0 ? ($journalByBranch[$branch['branch_id']] ?? null)?->id : null,
                    ]));
                }
            }

            foreach ($computed['zone_managers'] as $line) {
                $firstBranch = collect($line['contributions'])->first(fn (array $item): bool => $item['amount'] > 0 && isset($journalByBranch[$item['branch_id']]));
                $rows->push(CommissionAllocation::create([
                    'company_id' => $period->company_id,
                    'accounting_period_id' => $period->id,
                    'branch_id' => $line['branch_id'],
                    'zone_id' => $line['zone_id'],
                    'employee_id' => $line['employee_id'],
                    'kind' => CommissionAllocation::KIND_ZONE_MANAGER,
                    'distributable_profit' => 0,
                    'pool_percent' => $line['override_percent'],
                    'pool_amount' => $line['zone_pool'],
                    'zone_allocation' => $line['amount'],
                    'base_salary' => $line['base_salary'],
                    'total_salary' => 0,
                    'share_percent' => $line['override_percent'],
                    'amount' => $line['amount'],
                    'journal_entry_id' => $firstBranch !== null ? $journalByBranch[$firstBranch['branch_id']]->id : null,
                ]));
            }

            $period->update(['commission_calculated_at' => now(), 'commission_calculated_by' => $employee?->id]);

            AuditLog::create([
                'company_id' => $period->company_id,
                'employee_id' => $employee?->id,
                'action' => $previous->isEmpty() ? 'CommissionAllocation.calculated' : 'CommissionAllocation.recalculated',
                'auditable_type' => $period->getMorphClass(),
                'auditable_id' => $period->id,
                'before' => $previous->isEmpty() ? null : ['rows' => $previous->count(), 'total' => round((float) $previous->sum('amount'), 2)],
                'after' => ['rows' => $rows->count(), 'total' => round((float) $rows->sum('amount'), 2), 'returned_to_profit' => round((float) collect($computed['branches'])->sum('returned_to_profit_amount'), 2)],
                'context' => ['journals' => collect($journalByBranch)->map(fn (JournalEntry $entry): string => $entry->reference)->values()->all(), 'reversed_journals' => $reversed],
                'ip_address' => request()?->ip(),
            ]);

            return $rows;
        });
    }

    /**
     * Commission of one employee for a month (0 when not calculated).
     */
    public function amountFor(int $companyId, int $employeeId, CarbonImmutable $month): float
    {
        $period = $this->closedPeriod($companyId, $month);

        return $period === null ? 0.0 : round((float) CommissionAllocation::where('accounting_period_id', $period->id)->where('employee_id', $employeeId)->sum('amount'), 2);
    }

    public function isLocked(AccountingPeriod $period): bool
    {
        return $this->lockReason($period) !== null;
    }

    /**
     * Why the commission of a closed period can no longer be (re)calculated: its allocations are in an approved payroll, a
     * dividend declaration request of the month awaits approval, dividends were declared for the month (C1: after commission
     * was calculated; a LEGACY declaration without calculated commission locks it too), or HR finalised the commission for
     * payment (spec §21 / §22 — staff were shown the final figure, requests, approvals and payments refer to it). Null when
     * unlocked.
     */
    public function lockReason(AccountingPeriod $period): ?string
    {
        return $this->payrollOrDividendLockReason($period)
            ?? (CommissionAllocation::where('accounting_period_id', $period->id)->whereIn('payment_status', CommissionAllocation::FINALISED_STATUSES)->exists()
                ? self::LOCKED_BY_PAYMENT_MESSAGE
                : null);
    }

    private function payrollOrDividendLockReason(AccountingPeriod $period): ?string
    {
        $inPayroll = CommissionAllocation::where('accounting_period_id', $period->id)
            ->whereHas('payrollRun', fn ($query) => $query->where('status', '!=', PayrollRun::STATUS_DRAFT))
            ->exists();
        if ($inPayroll) {
            return self::LOCKED_MESSAGE;
        }

        if ($this->declaration($period) === null) {
            return DividendDeclarationRequest::pendingFor($period->company_id, $period->period_start)->exists()
                ? self::LOCKED_BY_DIVIDEND_REQUEST_MESSAGE
                : null;
        }

        return $this->isCalculated($period)
            ? self::LOCKED_BY_DIVIDEND_MESSAGE
            : str_replace(':month', $period->period_start->format('F Y'), self::LEGACY_DECLARED_WITHOUT_COMMISSION_MESSAGE);
    }

    /**
     * Commission of the period has been calculated: allocations are stored (legacy or profit-allocation rule) or a calculation
     * ran and stored none (every pool returned to profit, rule 4).
     */
    public function isCalculated(AccountingPeriod $period): bool
    {
        return $period->commission_calculated_at !== null
            || CommissionAllocation::where('accounting_period_id', $period->id)->exists();
    }

    /**
     * CALCULATED commission of a closed period per branch, as used by the dividend base and cap (rule 5): standing allocation
     * journals when calculated under the profit-allocation rule, stored rows for a legacy calculation (zone manager rows counted
     * at the manager's branch). Commission that is not calculated — expected, previewed or uncalculated — is never subtracted:
     * total 0. A pool with no eligible staff has no stored allocation and is therefore not subtracted either (rule 4).
     *
     * @return array{calculated: bool, total: float, branches: array<int, float>}
     */
    public function commissionByBranch(AccountingPeriod $period): array
    {
        $stored = CommissionAllocation::where('accounting_period_id', $period->id)->get();
        $branches = [];

        if ($stored->isEmpty()) {
            return ['calculated' => $this->isCalculated($period), 'total' => 0.0, 'branches' => []];
        }

        if (! $this->isLegacy($period, $stored)) {
            foreach ($this->allocationJournals($period) as $entry) {
                foreach ($entry->lines as $line) {
                    if ($line->account?->key === Account::RetainedProfit) {
                        $branches[(int) $line->account->branch_id] = round(($branches[(int) $line->account->branch_id] ?? 0) + (float) $line->debit - (float) $line->credit, 2);
                    }
                }
            }
        } else {
            foreach ($stored as $row) {
                $branches[(int) $row->branch_id] = round(($branches[(int) $row->branch_id] ?? 0) + (float) $row->amount, 2);
            }
        }

        return ['calculated' => true, 'total' => round((float) $stored->sum('amount'), 2), 'branches' => $branches];
    }

    /**
     * Standing (not reversed) profit-allocation journals of a period, with their lines and accounts.
     *
     * @return Collection<int, JournalEntry>
     */
    public function allocationJournals(AccountingPeriod $period): Collection
    {
        $resultIds = BranchPeriodResult::where('accounting_period_id', $period->id)->pluck('id');

        return JournalEntry::query()
            ->where('company_id', $period->company_id)
            ->where('transaction_type', TransactionType::CommissionAllocation->value)
            ->where('source_type', (new BranchPeriodResult)->getMorphClass())
            ->whereIn('source_id', $resultIds)
            ->whereNull('reversal_of_id')
            ->whereDoesntHave('reversal')
            ->with('lines.account')
            ->orderBy('id')
            ->get();
    }

    /**
     * LEGACY calculation: allocation rows stored without any journal by the pre-profit-allocation engine (June 2026). A calculation
     * under the current rule always stamps `commission_calculated_at`, so a month whose rows are all zero (every branch without
     * distributable profit) is not mistaken for legacy data.
     *
     * @param  Collection<int, CommissionAllocation>  $stored
     */
    private function isLegacy(AccountingPeriod $period, Collection $stored): bool
    {
        return $stored->isNotEmpty()
            && $stored->whereNotNull('journal_entry_id')->isEmpty()
            && $period->commission_calculated_at === null;
    }

    /**
     * LEGACY display only: dividends were declared (before C1) while commission was not calculated — no commission will ever be
     * allocated for the month, so the report shows no commission and every pool as left in the (already distributed) profit.
     *
     * @param  array{pool_percent: float, zone_override_percent: float, branches: list<array<string, mixed>>, zone_managers: list<array<string, mixed>>, total_commission: float}  $computed
     * @return array{pool_percent: float, zone_override_percent: float, branches: list<array<string, mixed>>, zone_managers: list<array<string, mixed>>, total_commission: float}
     */
    private function legacyDeclaredWithoutCommission(array $computed): array
    {
        $computed['branches'] = array_map(function (array $branch): array {
            $branch['staff'] = array_map(fn (array $line): array => ['amount' => 0.0] + $line, $branch['staff']);
            $branch['zone_manager_amount'] = 0.0;
            $branch['zone_manager_shares'] = [];
            $branch['staff_pool_amount'] = 0.0;
            $branch['unallocated_amount'] = $branch['pool_amount'];
            $branch['returned_to_profit_amount'] = $branch['pool_amount'];
            $branch['returned_no_zone_manager_amount'] = 0.0;
            $branch['returned_no_staff_amount'] = 0.0;

            return $branch;
        }, $computed['branches']);
        $computed['zone_managers'] = [];
        $computed['total_commission'] = 0.0;

        return $computed;
    }

    private function declaration(AccountingPeriod $period): ?DividendDeclaration
    {
        return DividendDeclaration::where('company_id', $period->company_id)->whereDate('period', $period->period_start->toDateString())->first();
    }

    /**
     * @return array{pool_percent: float, zone_override_percent: float, branches: list<array<string, mixed>>, zone_managers: list<array<string, mixed>>, total_commission: float}
     */
    private function compute(AccountingPeriod $period, HrmSetting $settings): array
    {
        $poolPercent = (float) $settings->commission_pool_percent;
        $overridePercent = (float) $settings->zone_override_percent;

        $results = BranchPeriodResult::where('accounting_period_id', $period->id)->with('branch')->orderBy('branch_id')->get();
        $employees = Employee::staff()->where('company_id', $period->company_id)
            ->where('status', 'active')
            ->whereHas('salaryInfo', fn ($query) => $query->where('salary', '>', 0)->where('commission_eligible', true))
            ->with('salaryInfo')
            ->orderBy('id')
            ->get();
        $zoneManagers = $employees
            ->filter(fn (Employee $employee): bool => $employee->salaryInfo->salary_type === SalaryType::ZoneManager->value && $employee->zone_id !== null)
            ->values();

        $branches = $results->map(function (BranchPeriodResult $result) use ($employees, $zoneManagers, $poolPercent, $overridePercent): array {
            $distributable = (float) $result->distributable_profit;
            $eligible = (bool) $result->commission_eligible && $this->moneySign($result->distributable_profit) > 0;
            $status = $this->profitStatus($result, $eligible);
            $offset = $eligible ? round(min($distributable, max(0.0, (float) $result->offset_amount)), 2) : 0.0;
            $commissionBase = $eligible ? round($distributable - $offset, 2) : 0.0;
            $pool = $commissionBase > 0 ? round($commissionBase * $poolPercent / 100, 2) : 0.0;

            $staff = $employees->filter(fn (Employee $employee): bool => $employee->branch_id === $result->branch_id && $employee->salaryInfo->salary_type === SalaryType::Branch->value)->values();
            $totalSalary = round((float) $staff->sum(fn (Employee $employee): float => (float) $employee->salaryInfo->salary), 2);

            $managers = $result->branch?->zone_id === null ? collect() : $zoneManagers->where('zone_id', $result->branch->zone_id)->values();
            $carve = $pool > 0 ? round($pool * $overridePercent / 100, 2) : 0.0;
            $staffPool = round($pool - $carve, 2);
            $paysManagers = $managers->isNotEmpty() && $staff->isNotEmpty();
            $managerAmount = $paysManagers ? $carve : 0.0;
            $returnedNoStaff = $staff->isEmpty() ? $pool : 0.0;
            $returnedNoManager = $staff->isNotEmpty() && ! $paysManagers ? $carve : 0.0;

            $shares = [];
            foreach ($paysManagers ? $this->bySalary($managers, $carve) : [] as $index => $amount) {
                $shares[] = ['employee_id' => $managers[$index]->id, 'employee' => $managers[$index]->full_name, 'amount' => $amount];
            }

            $amounts = $this->bySalary($staff, $staffPool);

            $lines = [];
            foreach ($staff as $index => $employee) {
                $salary = (float) $employee->salaryInfo->salary;
                $lines[] = [
                    'employee_id' => $employee->id,
                    'employee' => $employee->full_name,
                    'base_salary' => $salary,
                    'share_percent' => round(($totalSalary > 0 ? $salary / $totalSalary : 0) * 100, 4),
                    'amount' => $amounts[$index],
                ];
            }

            return [
                'branch_id' => $result->branch_id,
                'branch' => $result->branch?->name,
                'zone_id' => $result->branch?->zone_id,
                'total_income' => (float) $result->total_income,
                'expenses' => (float) $result->expenses,
                'gross_profit' => (float) $result->gross_profit,
                'loss_brought_forward' => (float) $result->loss_brought_forward,
                'net_profit' => (float) $result->net_profit,
                'loss_carried_forward' => (float) $result->loss_carried_forward,
                'hq_hold_amount' => (float) $result->hq_hold_amount,
                'distributable_profit' => $distributable,
                'offset_amount' => $offset,
                'commission_base' => $commissionBase,
                'eligible' => $eligible,
                'profit_status' => $status,
                'blocked_reason' => match ($status) {
                    self::PROFIT_LOSS => self::REASON_LOSS,
                    self::PROFIT_NONE => self::REASON_NO_PROFIT,
                    default => null,
                },
                'pool_amount' => $pool,
                'total_salary' => $totalSalary,
                'staff' => $lines,
                'zone_manager_percent' => $paysManagers ? $overridePercent : 0.0,
                'zone_manager_amount' => $managerAmount,
                'staff_pool_amount' => $staff->isEmpty() ? 0.0 : $staffPool,
                'zone_manager_shares' => $shares,
                'unallocated_amount' => round($returnedNoStaff + $returnedNoManager, 2),
                'returned_to_profit_amount' => round($returnedNoStaff + $returnedNoManager, 2),
                'returned_no_zone_manager_amount' => $returnedNoManager,
                'returned_no_staff_amount' => $returnedNoStaff,
            ];
        })->values();

        $managerLines = $zoneManagers->map(function (Employee $employee) use ($branches, $overridePercent): array {
            $zoneBranches = $branches->where('zone_id', $employee->zone_id);
            $contributions = $zoneBranches->map(fn (array $branch): array => [
                'branch' => $branch['branch'],
                'pool_amount' => $branch['pool_amount'],
                'branch_id' => $branch['branch_id'],
                'amount' => (float) (collect($branch['zone_manager_shares'])->firstWhere('employee_id', $employee->id)['amount'] ?? 0),
            ])->values();

            return [
                'employee_id' => $employee->id,
                'employee' => $employee->full_name,
                'zone_id' => $employee->zone_id,
                'branch_id' => $employee->branch_id,
                'base_salary' => (float) $employee->salaryInfo->salary,
                'contributions' => $contributions->all(),
                'zone_pool' => round((float) $zoneBranches->sum('pool_amount'), 2),
                'override_percent' => $overridePercent,
                'amount' => round((float) $contributions->sum('amount'), 2),
            ];
        })->values();

        return [
            'pool_percent' => $poolPercent,
            'zone_override_percent' => $overridePercent,
            'branches' => $branches->all(),
            'zone_managers' => $managerLines->all(),
            'total_commission' => round((float) $branches->sum(fn (array $branch): float => array_sum(array_column($branch['staff'], 'amount'))) + (float) $managerLines->sum('amount'), 2),
        ];
    }

    /**
     * Split an amount by salary: each line rounded to the cent, the last line takes the remainder, so the lines always sum
     * exactly to the amount (0 for every line when the amount is not positive).
     *
     * @param  Collection<int, Employee>  $employees
     * @return list<float>
     */
    private function bySalary(Collection $employees, float $amount): array
    {
        $total = (float) $employees->sum(fn (Employee $employee): float => (float) $employee->salaryInfo->salary);
        $amounts = [];
        $allocated = 0.0;

        foreach ($employees->values() as $index => $employee) {
            if ($amount <= 0) {
                $amounts[] = 0.0;

                continue;
            }
            $share = $total > 0 ? (float) $employee->salaryInfo->salary / $total : 0;
            $line = $index === $employees->count() - 1 ? round($amount - $allocated, 2) : round($amount * $share, 2);
            $allocated = round($allocated + $line, 2);
            $amounts[] = $line;
        }

        return $amounts;
    }

    /**
     * Eligible when the month-end marked the branch eligible with a positive distributable profit; otherwise a loss
     * (negative net profit, or a loss still carried forward) or simply no distributable profit (net profit of zero).
     */
    private function profitStatus(BranchPeriodResult $result, bool $eligible): string
    {
        if ($eligible) {
            return self::PROFIT_ELIGIBLE;
        }

        return $this->moneySign($result->net_profit) < 0 || $this->moneySign($result->loss_carried_forward) > 0
            ? self::PROFIT_LOSS
            : self::PROFIT_NONE;
    }

    /**
     * Sign of a money amount compared at cent precision (-1, 0 or 1) without float rounding surprises.
     */
    private function moneySign(string|float|int|null $amount): int
    {
        $value = is_string($amount) && preg_match('/^-?\d+(\.\d+)?$/', $amount) === 1 ? $amount : number_format((float) $amount, 2, '.', '');

        return bccomp($value, '0', 2);
    }

    private function notClosedMessage(CarbonImmutable $month): string
    {
        return "The period {$month->format('F Y')} is not closed. Close the month before calculating commission.";
    }

    /**
     * Report counts: eligible branches, branches blocked by a loss and branches without distributable profit.
     *
     * @param  list<array<string, mixed>>  $branches
     * @return array{branches_eligible: int, branches_in_loss: int, branches_no_profit: int, total_pools: float}
     */
    public function summary(array $branches): array
    {
        $branches = collect($branches);

        return [
            'branches_eligible' => $branches->where('profit_status', self::PROFIT_ELIGIBLE)->count(),
            'branches_in_loss' => $branches->where('profit_status', self::PROFIT_LOSS)->count(),
            'branches_no_profit' => $branches->where('profit_status', self::PROFIT_NONE)->count(),
            'total_pools' => round((float) $branches->sum('pool_amount'), 2),
        ];
    }

    /**
     * A calculated branch keeps the pool it was calculated with (closed history is never recomputed). Its commission base and
     * offset are derived from that stored pool, so a period calculated before the offset rule shows no offset.
     *
     * @param  array<string, mixed>  $branch
     * @return array<string, mixed>
     */
    private function withStoredPool(array $branch, CommissionAllocation $row): array
    {
        $computedPool = (float) $branch['pool_amount'];
        $branch['pool_amount'] = (float) $row->pool_amount;
        if (abs($computedPool - $branch['pool_amount']) < 0.005) {
            return $branch;
        }
        $percent = (float) $row->pool_percent;
        $base = $percent > 0 ? round($branch['pool_amount'] * 100 / $percent, 2) : (float) $branch['commission_base'];
        $branch['commission_base'] = $base;
        $branch['offset_amount'] = round(max(0.0, (float) $branch['distributable_profit'] - $base), 2);

        return $branch;
    }

    /**
     * LEGACY calculation (allocations without a journal): overlay stored allocations on the computed branch figures (stored
     * amounts win) exactly as they were reported when calculated — the zone manager override on top of the pools.
     *
     * @param  Collection<int, CommissionAllocation>  $stored
     * @param  array{pool_percent: float, zone_override_percent: float, branches: list<array<string, mixed>>, zone_managers: list<array<string, mixed>>, total_commission: float}  $computed
     * @return array{branches: list<array<string, mixed>>, zone_managers: list<array<string, mixed>>, total_commission: float}
     */
    private function fromStored(AccountingPeriod $period, Collection $stored, array $computed): array
    {
        $branchStaff = $stored->where('kind', CommissionAllocation::KIND_BRANCH_STAFF);

        $branches = array_map(function (array $branch) use ($branchStaff): array {
            $rows = $branchStaff->where('branch_id', $branch['branch_id']);
            if ($rows->isNotEmpty()) {
                $branch = $this->withStoredPool($branch, $rows->first());
            }
            $branch['staff'] = $rows->map(fn (CommissionAllocation $row): array => [
                'employee_id' => $row->employee_id,
                'employee' => $row->employee?->full_name,
                'base_salary' => (float) $row->base_salary,
                'share_percent' => (float) $row->share_percent,
                'amount' => (float) $row->amount,
            ])->values()->all();
            $branch['zone_manager_percent'] = 0.0;
            $branch['zone_manager_amount'] = null;
            $branch['staff_pool_amount'] = $branch['pool_amount'];
            $branch['zone_manager_shares'] = [];
            $branch['unallocated_amount'] = 0.0;
            $branch['returned_to_profit_amount'] = 0.0;
            $branch['returned_no_zone_manager_amount'] = 0.0;
            $branch['returned_no_staff_amount'] = 0.0;

            return $branch;
        }, $computed['branches']);

        $zoneManagers = $stored->where('kind', CommissionAllocation::KIND_ZONE_MANAGER)->map(function (CommissionAllocation $row) use ($computed): array {
            $preview = collect($computed['zone_managers'])->firstWhere('employee_id', $row->employee_id);

            return [
                'employee_id' => $row->employee_id,
                'employee' => $row->employee?->full_name,
                'zone_id' => $row->zone_id,
                'branch_id' => $row->branch_id,
                'base_salary' => (float) $row->base_salary,
                'contributions' => array_map(fn (array $item): array => ['branch' => $item['branch'], 'pool_amount' => $item['pool_amount']], $preview['contributions'] ?? []),
                'zone_pool' => (float) $row->pool_amount,
                'override_percent' => (float) $row->pool_percent,
                'amount' => (float) $row->amount,
            ];
        })->values()->all();

        return [
            'branches' => $branches,
            'zone_managers' => $zoneManagers,
            'total_commission' => round((float) $stored->sum('amount'), 2),
        ];
    }

    /**
     * PROFIT-ALLOCATION calculation: stored rows plus the standing journals (per-branch carve-outs of zone managers come
     * from the COMMISSION PAYABLE lines, each branch shows its journal reference). Returned to profit = pool − staff − zone
     * manager credits: the 5 % without an eligible zone manager when staff were paid, otherwise the whole pool (no eligible
     * staff). Months calculated before C4 (staff took 100 % without a zone manager) therefore show nothing returned, as booked.
     *
     * @param  Collection<int, CommissionAllocation>  $stored
     * @param  array{pool_percent: float, zone_override_percent: float, branches: list<array<string, mixed>>, zone_managers: list<array<string, mixed>>, total_commission: float}  $computed
     * @param  Collection<int, JournalEntry>  $journals
     * @return array{branches: list<array<string, mixed>>, zone_managers: list<array<string, mixed>>, total_commission: float}
     */
    private function fromJournals(Collection $stored, array $computed, Collection $journals): array
    {
        $staffRows = $stored->where('kind', CommissionAllocation::KIND_BRANCH_STAFF);
        $managerRows = $stored->where('kind', CommissionAllocation::KIND_ZONE_MANAGER)->keyBy('employee_id');
        $managerIds = $managerRows->keys()->map(fn ($id): int => (int) $id)->all();

        /** @var array<int, array<int, float>> $credits branch → employee → credited */
        $credits = [];
        $references = [];
        foreach ($journals as $entry) {
            $references[(int) $entry->branch_id] = $entry->reference;
            foreach ($entry->lines as $line) {
                if ($line->account?->key === Account::CommissionPayable) {
                    $credits[(int) $line->account->branch_id][(int) $line->account->employee_id] = round(($credits[(int) $line->account->branch_id][(int) $line->account->employee_id] ?? 0) + (float) $line->credit, 2);
                }
            }
        }

        $branches = array_map(function (array $branch) use ($staffRows, $credits, $references, $managerIds, $managerRows): array {
            $rows = $staffRows->where('branch_id', $branch['branch_id']);
            if ($rows->isNotEmpty()) {
                $branch = $this->withStoredPool($branch, $rows->first());
            }
            $branch['staff'] = $rows->map(fn (CommissionAllocation $row): array => [
                'employee_id' => $row->employee_id,
                'employee' => $row->employee?->full_name,
                'base_salary' => (float) $row->base_salary,
                'share_percent' => (float) $row->share_percent,
                'amount' => (float) $row->amount,
            ])->values()->all();
            $shares = [];
            foreach ($credits[$branch['branch_id']] ?? [] as $employeeId => $amount) {
                if (in_array($employeeId, $managerIds, true)) {
                    $shares[] = ['employee_id' => $employeeId, 'employee' => $managerRows[$employeeId]->employee?->full_name, 'amount' => $amount];
                }
            }
            $branch['zone_manager_shares'] = $shares;
            $branch['zone_manager_amount'] = round(array_sum(array_column($shares, 'amount')), 2);
            $staffTotal = round(array_sum(array_column($branch['staff'], 'amount')), 2);
            $hasStaff = $staffTotal > 0;
            $branch['staff_pool_amount'] = $hasStaff ? $staffTotal : 0.0;
            $branch['journal_reference'] = $references[$branch['branch_id']] ?? null;
            $returned = round($branch['pool_amount'] - $staffTotal - $branch['zone_manager_amount'], 2);
            $branch['unallocated_amount'] = $returned > 0 ? $returned : 0.0;
            $branch['returned_to_profit_amount'] = $branch['unallocated_amount'];
            $branch['returned_no_zone_manager_amount'] = $hasStaff ? $branch['unallocated_amount'] : 0.0;
            $branch['returned_no_staff_amount'] = $hasStaff ? 0.0 : $branch['unallocated_amount'];

            return $branch;
        }, $computed['branches']);

        $zoneManagers = $managerRows->map(function (CommissionAllocation $row) use ($branches): array {
            $contributions = collect($branches)
                ->filter(fn (array $branch): bool => $branch['zone_id'] !== null && (int) $branch['zone_id'] === (int) $row->zone_id)
                ->map(fn (array $branch): array => [
                    'branch' => $branch['branch'],
                    'pool_amount' => $branch['pool_amount'],
                    'branch_id' => $branch['branch_id'],
                    'amount' => (float) (collect($branch['zone_manager_shares'])->firstWhere('employee_id', $row->employee_id)['amount'] ?? 0),
                ])->values()->all();

            return [
                'employee_id' => $row->employee_id,
                'employee' => $row->employee?->full_name,
                'zone_id' => $row->zone_id,
                'branch_id' => $row->branch_id,
                'base_salary' => (float) $row->base_salary,
                'contributions' => $contributions,
                'zone_pool' => (float) $row->pool_amount,
                'override_percent' => (float) $row->pool_percent,
                'amount' => (float) $row->amount,
            ];
        })->values()->all();

        return [
            'branches' => $branches,
            'zone_managers' => $zoneManagers,
            'total_commission' => round((float) $stored->sum('amount'), 2),
        ];
    }
}
