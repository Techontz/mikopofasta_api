<?php

namespace App\Services\Accounting;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\AccountingPeriod;
use App\Models\Capital;
use App\Models\CommissionAllocation;
use App\Models\Company;
use App\Models\DividendDeclaration;
use App\Models\LegacyImport;
use App\Models\Loan;
use App\Models\LoanRecovery;
use App\Models\LoanTransaction;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\WriteOff;
use App\Services\Hrm\CommissionEngine;
use App\Services\LoanRecoveryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only ledger integrity and sub-ledger reconciliation (Fund Flow Specification §20, §27).
 *
 * Each check returns a status: `fail` (the books are wrong), `warn` (inconsistent with the specification or a
 * sub-ledger, needs review), `info` (figures reported for review) or `pass`. Nothing is ever written.
 *
 * @phpstan-type Check array{key: string, title: string, status: string, message: string, details: array<string, mixed>}
 */
class LedgerIntegrity
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const WARN = 'warn';

    public const INFO = 'info';

    /** Money tolerance (half a cent). */
    private const TOLERANCE = 0.005;

    /** Maximum rows listed in a check's details. */
    private const SAMPLE = 25;

    public function __construct(private readonly CommissionEngine $commission) {}

    /**
     * Fund (money) accounts whose balance may never go below zero (spec §31).
     *
     * @return list<Account>
     */
    public static function nonNegativeFunds(): array
    {
        return [
            Account::Principal, Account::Interest, Account::LoanFee, Account::Penalty, Account::Reserve, Account::Insurance,
            Account::Company, Account::Bank, Account::InvestmentReserve, Account::PettyCash, Account::StaffFundCash, ...Account::hqAccounts(),
        ];
    }

    /**
     * Run every check for one company.
     *
     * @return array{company_id: int, company: ?string, generated_at: string, status: string, summary: array<string, int>, checks: list<Check>}
     */
    public function run(int $companyId): array
    {
        $checks = [
            $this->debitsEqualCredits($companyId),
            $this->entriesBalanced($companyId),
            $this->orphans($companyId),
            $this->lineAmounts($companyId),
            $this->reversals($companyId),
            $this->closedPeriodBackdating($companyId),
            $this->closedPeriodsNetToZero($companyId),
            $this->sourcesExist($companyId),
            $this->loanReceivable($companyId),
            $this->capital($companyId),
            $this->dividendPayable($companyId),
            $this->suspense($companyId),
            $this->tellerCash($companyId),
            $this->staffPayable($companyId),
            $this->bank($companyId),
            $this->negativeFunds($companyId),
            $this->missingTransactionType($companyId),
            $this->commissionPayable($companyId),
            $this->penaltyReceivable($companyId),
            $this->legacyPenalties($companyId),
            $this->interestReserve($companyId),
            $this->reinvestedProfit($companyId),
            $this->profitDistribution($companyId),
            $this->reserveUntouchedByProfitChain($companyId),
            $this->insuranceReserve($companyId),
            $this->loanRecoveries($companyId),
        ];

        $summary = collect([self::PASS, self::FAIL, self::WARN, self::INFO])
            ->mapWithKeys(fn (string $status): array => [$status => collect($checks)->where('status', $status)->count()])
            ->all();

        return [
            'company_id' => $companyId,
            'company' => Company::whereKey($companyId)->value('name'),
            'generated_at' => now()->toDateTimeString(),
            'status' => $summary[self::FAIL] > 0 ? self::FAIL : ($summary[self::WARN] > 0 ? self::WARN : self::PASS),
            'summary' => $summary,
            'checks' => $checks,
        ];
    }

    /**
     * @return Check
     */
    private function debitsEqualCredits(int $companyId): array
    {
        $totals = $this->lines($companyId)->selectRaw('COALESCE(SUM(journal_lines.debit), 0) AS debits, COALESCE(SUM(journal_lines.credit), 0) AS credits')->first();
        $debits = round((float) $totals->debits, 2);
        $credits = round((float) $totals->credits, 2);
        $ok = abs($debits - $credits) < self::TOLERANCE;

        return $this->check('debits_equal_credits', 'Total debits equal total credits', $ok ? self::PASS : self::FAIL,
            $ok ? 'Debits and credits agree.' : 'Total debits differ from total credits by '.$this->money($debits - $credits).'.',
            ['entries' => DB::table('journal_entries')->where('company_id', $companyId)->count(), 'debits' => $debits, 'credits' => $credits, 'difference' => round($debits - $credits, 2)]);
    }

    /**
     * @return Check
     */
    private function entriesBalanced(int $companyId): array
    {
        $unbalanced = $this->lines($companyId)
            ->groupBy('journal_entries.id', 'journal_entries.reference')
            ->havingRaw('ABS(SUM(journal_lines.debit) - SUM(journal_lines.credit)) >= ?', [self::TOLERANCE])
            ->selectRaw('journal_entries.id, journal_entries.reference, SUM(journal_lines.debit) AS debits, SUM(journal_lines.credit) AS credits')
            ->get();

        return $this->check('entries_balanced', 'Every journal entry is balanced', $unbalanced->isEmpty() ? self::PASS : self::FAIL,
            $unbalanced->isEmpty() ? 'All entries balance.' : $unbalanced->count().' unbalanced entries.',
            ['unbalanced' => $unbalanced->take(self::SAMPLE)->map(fn ($row): array => ['id' => $row->id, 'reference' => $row->reference, 'debits' => round((float) $row->debits, 2), 'credits' => round((float) $row->credits, 2)])->values()->all()]);
    }

    /**
     * @return Check
     */
    private function orphans(int $companyId): array
    {
        $withoutLines = DB::table('journal_entries')->where('company_id', $companyId)
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('journal_lines')->whereColumn('journal_lines.journal_entry_id', 'journal_entries.id'))
            ->pluck('reference');

        $linesWithoutEntry = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.company_id', $companyId)
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('journal_entries')->whereColumn('journal_entries.id', 'journal_lines.journal_entry_id'))
            ->pluck('journal_lines.id');

        $linesWithoutAccount = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('accounts')->whereColumn('accounts.id', 'journal_lines.account_id'))
            ->pluck('journal_lines.id');

        $crossCompany = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.company_id', $companyId)
            ->whereColumn('accounts.company_id', '!=', 'journal_entries.company_id')
            ->pluck('journal_entries.reference');

        $ok = $withoutLines->isEmpty() && $linesWithoutEntry->isEmpty() && $linesWithoutAccount->isEmpty() && $crossCompany->isEmpty();

        return $this->check('orphans', 'No orphan entries, lines or cross-company lines', $ok ? self::PASS : self::FAIL,
            $ok ? 'Every entry has lines and every line belongs to an entry and an account of the same company.' : 'Orphan or cross-company rows found.',
            [
                'entries_without_lines' => $withoutLines->take(self::SAMPLE)->values()->all(),
                'lines_without_entry' => $linesWithoutEntry->take(self::SAMPLE)->values()->all(),
                'lines_without_account' => $linesWithoutAccount->take(self::SAMPLE)->values()->all(),
                'cross_company_lines' => $crossCompany->unique()->take(self::SAMPLE)->values()->all(),
            ]);
    }

    /**
     * @return Check
     */
    private function lineAmounts(int $companyId): array
    {
        $bad = $this->lines($companyId)
            ->where(fn (Builder $query) => $query->where('journal_lines.debit', '<', 0)
                ->orWhere('journal_lines.credit', '<', 0)
                ->orWhere(fn (Builder $both) => $both->where('journal_lines.debit', '>', 0)->where('journal_lines.credit', '>', 0))
                ->orWhere(fn (Builder $none) => $none->where('journal_lines.debit', '=', 0)->where('journal_lines.credit', '=', 0)))
            ->get(['journal_lines.id', 'journal_entries.reference', 'journal_lines.debit', 'journal_lines.credit']);

        return $this->check('line_amounts', 'Line amounts are positive and one-sided', $bad->isEmpty() ? self::PASS : self::FAIL,
            $bad->isEmpty() ? 'Every line is a positive debit or a positive credit.' : $bad->count().' invalid lines (negative, zero or both sides).',
            ['invalid_lines' => $bad->take(self::SAMPLE)->map(fn ($row): array => (array) $row)->values()->all()]);
    }

    /**
     * @return Check
     */
    private function reversals(int $companyId): array
    {
        $problems = [];

        $reversals = DB::table('journal_entries as reversal')
            ->leftJoin('journal_entries as original', 'original.id', '=', 'reversal.reversal_of_id')
            ->where('reversal.company_id', $companyId)
            ->whereNotNull('reversal.reversal_of_id')
            ->get(['reversal.id', 'reversal.reference', 'reversal.reversal_of_id', 'original.id as original_id', 'original.company_id as original_company_id', 'original.reversal_of_id as original_reversal_of_id']);

        foreach ($reversals as $reversal) {
            if ($reversal->original_id === null) {
                $problems[] = ['reference' => $reversal->reference, 'problem' => 'points to a missing entry'];
            } elseif ((int) $reversal->original_company_id !== $companyId) {
                $problems[] = ['reference' => $reversal->reference, 'problem' => 'points to an entry of another company'];
            } elseif ($reversal->original_reversal_of_id !== null) {
                $problems[] = ['reference' => $reversal->reference, 'problem' => 'reverses a reversal'];
            }
        }

        foreach ($reversals->groupBy('reversal_of_id')->filter(fn (Collection $group): bool => $group->count() > 1) as $originalId => $group) {
            $problems[] = ['reference' => $group->pluck('reference')->implode(', '), 'problem' => "entry #{$originalId} is reversed more than once"];
        }

        $sums = DB::table('journal_lines')
            ->whereIn('journal_entry_id', $reversals->pluck('id')->merge($reversals->pluck('original_id'))->filter()->unique()->values())
            ->groupBy('journal_entry_id', 'account_id')
            ->selectRaw('journal_entry_id, account_id, SUM(debit) AS debits, SUM(credit) AS credits')
            ->get()
            ->groupBy('journal_entry_id');

        foreach ($reversals->whereNotNull('original_id') as $reversal) {
            $mirror = fn (Collection $rows, bool $swap): array => $rows->mapWithKeys(fn ($row): array => [
                $row->account_id => [round((float) ($swap ? $row->credits : $row->debits), 2), round((float) ($swap ? $row->debits : $row->credits), 2)],
            ])->sortKeys()->all();

            if ($mirror($sums->get($reversal->id, collect()), true) !== $mirror($sums->get($reversal->original_id, collect()), false)) {
                $problems[] = ['reference' => $reversal->reference, 'problem' => 'lines do not mirror the original entry'];
            }
        }

        return $this->check('reversals', 'Reversals point to one original and mirror it', $problems === [] ? self::PASS : self::FAIL,
            $problems === [] ? $reversals->count().' reversal entries verified.' : count($problems).' reversal problems.',
            ['reversal_entries' => $reversals->count(), 'problems' => array_slice($problems, 0, self::SAMPLE)]);
    }

    /**
     * @return Check
     */
    private function closedPeriodBackdating(int $companyId): array
    {
        $closingTypes = [TransactionType::MonthEndClosing->value, TransactionType::HqProfitHold->value];
        $lastClosing = DB::table('journal_entries')
            ->where('company_id', $companyId)
            ->where('source_type', (new AccountingPeriod)->getMorphClass())
            ->whereIn('transaction_type', $closingTypes)
            ->groupBy('source_id')
            ->selectRaw('source_id AS period_id, MAX(id) AS last_closing_id');

        // Order-based: an entry is back-dated when it was posted (higher id) after its closed period's last closing entry.
        // A period without closing entries falls back to the wall-clock closed_at.
        $backdated = DB::table('journal_entries')
            ->join('accounting_periods', function ($join): void {
                $join->on('accounting_periods.company_id', '=', 'journal_entries.company_id')
                    ->whereColumn('journal_entries.entry_date', '>=', 'accounting_periods.period_start')
                    ->whereColumn('journal_entries.entry_date', '<=', 'accounting_periods.period_end');
            })
            ->leftJoinSub($lastClosing, 'closing', 'closing.period_id', '=', 'accounting_periods.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('accounting_periods.status', AccountingPeriod::STATUS_CLOSED)
            ->whereNull('journal_entries.reversal_of_id')
            ->where(fn (Builder $query) => $query->whereNull('journal_entries.transaction_type')->orWhereNotIn('journal_entries.transaction_type', $closingTypes))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $ordered) => $ordered->whereNotNull('closing.last_closing_id')->whereColumn('journal_entries.id', '>', 'closing.last_closing_id'))
                ->orWhere(fn (Builder $clock) => $clock->whereNull('closing.last_closing_id')->whereNotNull('accounting_periods.closed_at')->whereColumn('journal_entries.created_at', '>', 'accounting_periods.closed_at')))
            ->selectRaw('journal_entries.reference, journal_entries.entry_date, journal_entries.created_at, accounting_periods.closed_at, closing.last_closing_id')
            ->selectRaw("EXISTS (SELECT 1 FROM journal_lines JOIN accounts ON accounts.id = journal_lines.account_id WHERE journal_lines.journal_entry_id = journal_entries.id AND accounts.type IN ('income', 'expense')) AS affects_profit")
            ->get()
            ->map(fn ($row): array => ['reference' => $row->reference, 'entry_date' => $row->entry_date, 'created_at' => $row->created_at, 'closed_at' => $row->closed_at, 'last_closing_entry_id' => $row->last_closing_id, 'affects_profit' => (bool) $row->affects_profit]);

        $affectsProfit = $backdated->where('affects_profit', true)->count();
        $status = $backdated->isEmpty() ? self::PASS : ($affectsProfit > 0 ? self::FAIL : self::WARN);

        return $this->check('closed_period_backdating', 'Nothing back-dated into closed periods', $status,
            match ($status) {
                self::PASS => 'No entry was posted into a period after its closing entries.',
                self::WARN => $backdated->count().' balance-sheet entries (no income or expense) were dated inside a closed period after it closed.',
                default => $affectsProfit.' entries changing income or expense were dated inside a closed period after it closed.',
            },
            ['entries' => $backdated->take(self::SAMPLE)->values()->all()]);
    }

    /**
     * @return Check
     */
    private function closedPeriodsNetToZero(int $companyId): array
    {
        $open = [];
        $periods = AccountingPeriod::query()->where('company_id', $companyId)->closed()->orderBy('period_start')->get();

        foreach ($periods as $period) {
            $rows = $this->lines($companyId)
                ->whereIn('accounts.type', ['income', 'expense'])
                ->whereBetween('journal_entries.entry_date', [$period->period_start->toDateString(), $period->period_end->toDateString()])
                ->groupBy('accounts.id', 'accounts.key', 'accounts.branch_id')
                ->havingRaw('ABS(SUM(journal_lines.debit) - SUM(journal_lines.credit)) >= ?', [self::TOLERANCE])
                ->selectRaw('accounts.id, accounts.key, accounts.branch_id, SUM(journal_lines.debit) - SUM(journal_lines.credit) AS net')
                ->get();

            foreach ($rows as $row) {
                $open[] = ['period' => $period->period_start->format('Y-m'), 'account' => $row->key, 'account_id' => $row->id, 'branch_id' => $row->branch_id, 'net' => round((float) $row->net, 2)];
            }
        }

        return $this->check('closed_periods_closed', 'Closed periods: income and expense net to zero', $open === [] ? self::PASS : self::FAIL,
            $open === [] ? $periods->count().' closed periods verified.' : count($open).' income/expense accounts still carry a balance inside a closed period.',
            ['closed_periods' => $periods->count(), 'accounts' => array_slice($open, 0, self::SAMPLE)]);
    }

    /**
     * @return Check
     */
    private function sourcesExist(int $companyId): array
    {
        $missing = [];
        $warnings = [];
        $types = DB::table('journal_entries')->where('company_id', $companyId)->whereNotNull('source_type')->distinct()->pluck('source_type');

        foreach ($types as $type) {
            $class = Relation::getMorphedModel($type) ?? $type;
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                $missing[] = ['source_type' => $type, 'problem' => 'unknown source model'];

                continue;
            }

            /** @var Model $model */
            $model = new $class;
            $rows = DB::table('journal_entries')
                ->where('company_id', $companyId)
                ->where('source_type', $type)
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from($model->getTable())->whereColumn($model->getTable().'.'.$model->getKeyName(), 'journal_entries.source_id'))
                ->get(['id', 'reference', 'source_id', 'reversal_of_id'])
                ->map(function ($row): array {
                    $row->reversed = $row->reversal_of_id !== null || DB::table('journal_entries')->where('reversal_of_id', $row->id)->exists();

                    return (array) $row;
                });

            foreach ($rows as $row) {
                $item = ['source_type' => class_basename($type), 'source_id' => $row['source_id'], 'reference' => $row['reference']];
                $row['reversed'] ? $warnings[] = $item : $missing[] = $item;
            }
        }

        $status = $missing !== [] ? self::FAIL : ($warnings !== [] ? self::WARN : self::PASS);

        return $this->check('sources_exist', 'Sourced entries point to an existing record', $status,
            match ($status) {
                self::PASS => 'Every sourced entry has its source record.',
                self::WARN => count($warnings).' reversed entries whose source record was removed.',
                default => count($missing).' entries whose source record does not exist.',
            },
            ['missing' => array_slice($missing, 0, self::SAMPLE), 'reversed_with_missing_source' => array_slice($warnings, 0, self::SAMPLE)]);
    }

    /**
     * LOAN RECEIVABLE per branch = Σ (approved principal − principal repaid) of disbursed loans that are not written off.
     * Only journals that still stand (not reversed) count as disbursed / repaid.
     *
     * @return Check
     */
    private function loanReceivable(int $companyId): array
    {
        $ledger = $this->balancesBy($companyId, Account::LoanReceivable, 'branch_id');

        $disbursed = DB::table('loans')
            ->where('loans.company_id', $companyId)
            ->where('loans.status', '!=', LoanStatus::WrittenOff->value)
            ->whereExists(fn (Builder $query) => $this->standingEntries($query, (new Loan)->getMorphClass(), 'loans.id', TransactionType::LoanDisbursement))
            ->groupBy('loans.branch_id')
            ->selectRaw('loans.branch_id, SUM(loans.amount_approved) AS principal, COUNT(*) AS loans')
            ->get()
            ->keyBy('branch_id');

        // Loans carried over from the old system enter LOAN RECEIVABLE through their import's opening journal (Dr LOAN
        // RECEIVABLE / Cr OLD SYSTEM OPENING BALANCE) at what was still owed: the printed Loan Amount less what the old
        // system had already collected.
        $opening = DB::table('loans')
            ->join('legacy_import_rows', 'legacy_import_rows.id', '=', 'loans.legacy_import_row_id')
            ->join('legacy_imports', 'legacy_imports.id', '=', 'legacy_import_rows.legacy_import_id')
            ->where('loans.company_id', $companyId)
            ->where('loans.is_legacy_opening', true)
            ->where('loans.status', '!=', LoanStatus::WrittenOff->value)
            ->whereExists(fn (Builder $query) => $this->standingEntries($query, (new LegacyImport)->getMorphClass(), 'legacy_imports.id', TransactionType::OpeningBalance))
            ->groupBy('loans.branch_id')
            ->selectRaw('loans.branch_id, SUM(loans.amount_approved - loans.opening_paid_principal) AS principal, COUNT(*) AS loans')
            ->get()
            ->keyBy('branch_id');
        foreach ($opening as $branchId => $row) {
            $current = $disbursed->get($branchId);
            $disbursed->put($branchId, (object) [
                'branch_id' => $branchId,
                'principal' => (float) ($current?->principal ?? 0) + (float) $row->principal,
                'loans' => (int) ($current?->loans ?? 0) + (int) $row->loans,
            ]);
        }

        $repaid = DB::table('loan_transactions')
            ->join('loans', 'loans.id', '=', 'loan_transactions.loan_id')
            ->where('loans.company_id', $companyId)
            ->where('loans.status', '!=', LoanStatus::WrittenOff->value)
            ->where('loan_transactions.type', 'deposit')
            ->whereNull('loan_transactions.reversed_at')
            ->whereExists(fn (Builder $query) => $this->standingEntries($query, (new LoanTransaction)->getMorphClass(), 'loan_transactions.id'))
            ->groupBy('loans.branch_id')
            ->selectRaw('loans.branch_id, SUM(loan_transactions.principal) AS principal')
            ->pluck('principal', 'branch_id');

        $rows = [];
        foreach ($ledger->keys()->merge($disbursed->keys())->unique() as $branchId) {
            $expected = round((float) ($disbursed->get($branchId)?->principal ?? 0) - (float) ($repaid[$branchId] ?? 0), 2);
            $actual = round((float) ($ledger[$branchId] ?? 0), 2);
            $rows[] = ['branch_id' => $branchId === '' ? null : $branchId, 'loans' => (int) ($disbursed->get($branchId)?->loans ?? 0), 'ledger' => $actual, 'loans_outstanding_principal' => $expected, 'difference' => round($actual - $expected, 2)];
        }

        $differences = array_values(array_filter($rows, fn (array $row): bool => abs($row['difference']) >= self::TOLERANCE));

        return $this->check('loan_receivable', 'Loan receivable = outstanding principal of disbursed loans', $differences === [] ? self::PASS : self::FAIL,
            $differences === [] ? 'Loan receivable agrees with the loan book in every branch.' : count($differences).' branches differ from the loan book.',
            ['branches' => $rows]);
    }

    /**
     * CAPITAL ledger = Σ standing capital contributions + reinvestment credited by dividend declarations (+ other credits).
     *
     * @return Check
     */
    private function capital(int $companyId): array
    {
        $bySource = $this->lines($companyId)
            ->where('accounts.key', Account::Capital->value)
            ->groupBy('journal_entries.source_type')
            ->selectRaw('journal_entries.source_type, SUM(journal_lines.credit) - SUM(journal_lines.debit) AS net')
            ->pluck('net', 'source_type');

        $ledgerTotal = round((float) $bySource->sum(), 2);
        $fromContributions = round((float) ($bySource[(new Capital)->getMorphClass()] ?? 0), 2);
        $fromDeclarations = round((float) ($bySource[(new DividendDeclaration)->getMorphClass()] ?? 0), 2);
        $other = round($ledgerTotal - $fromContributions - $fromDeclarations, 2);
        $register = round((float) DB::table('capitals')->where('company_id', $companyId)->whereNull('reversed_at')->where('status', 'posted')->sum('amount'), 2);

        $status = abs($fromContributions - $register) >= self::TOLERANCE ? self::FAIL : (abs($other) >= self::TOLERANCE ? self::WARN : self::PASS);

        return $this->check('capital', 'Capital ledger = contributions + legacy reinvestment', $status,
            match ($status) {
                self::PASS => 'Capital = stakeholder contributions + reinvestment credited to capital by legacy dividend declarations (new declarations credit REINVESTED PROFIT).',
                self::WARN => 'Capital includes '.$this->money($other).' not linked to a stakeholder contribution or declaration (e.g. opening balances).',
                default => 'Capital posted by contributions ('.$this->money($fromContributions).') differs from the contribution register ('.$this->money($register).').',
            },
            [
                'ledger_capital' => $ledgerTotal,
                'contributions_register' => $register,
                'contributions_posted' => $fromContributions,
                'reinvestment_from_declarations' => $fromDeclarations,
                'legacy_reinvestment_credits' => $fromDeclarations,
                'other_credits' => $other,
            ]);
    }

    /**
     * @return Check
     */
    private function dividendPayable(int $companyId): array
    {
        $ledger = $this->balance($companyId, Account::DividendPayable);
        $outstanding = round((float) DB::table('dividend_allocations')->where('company_id', $companyId)->selectRaw('COALESCE(SUM(amount - paid_amount), 0) AS outstanding')->value('outstanding'), 2);
        $ok = abs($ledger - $outstanding) < self::TOLERANCE;

        return $this->check('dividend_payable', 'Dividend payable = unpaid allocations', $ok ? self::PASS : self::FAIL,
            $ok ? 'Dividend payable agrees with unpaid shareholder allocations.' : 'Dividend payable differs from unpaid allocations by '.$this->money($ledger - $outstanding).'.',
            ['ledger' => $ledger, 'allocations_outstanding' => $outstanding, 'difference' => round($ledger - $outstanding, 2)]);
    }

    /**
     * @return Check
     */
    private function suspense(int $companyId): array
    {
        // One central pending account (§11): a branch's share is the branch its entries were recorded for; lines from
        // before centralisation still carry the branch on the account row.
        $ledger = $this->lines($companyId)
            ->where('accounts.key', Account::Suspense->value)
            ->groupByRaw('COALESCE(accounts.branch_id, journal_entries.branch_id)')
            ->selectRaw('COALESCE(accounts.branch_id, journal_entries.branch_id) AS grouping_key, SUM(journal_lines.credit) - SUM(journal_lines.debit) AS balance')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->grouping_key => round((float) $row->balance, 2)]);
        $held = DB::table('payments')
            ->where('company_id', $companyId)
            ->whereIn('status', PaymentStatus::values(PaymentStatus::PendingVerification, PaymentStatus::Deposited, PaymentStatus::Unallocated, PaymentStatus::Flagged))
            ->groupBy('branch_id')
            ->selectRaw('branch_id, SUM(amount - allocated_amount) AS held')
            ->pluck('held', 'branch_id');

        $rows = $ledger->keys()->merge($held->keys())->unique()->map(fn ($branchId): array => [
            'branch_id' => $branchId === '' ? null : $branchId,
            'ledger' => round((float) ($ledger[$branchId] ?? 0), 2),
            'payments_held' => round((float) ($held[$branchId] ?? 0), 2),
            'difference' => round((float) ($ledger[$branchId] ?? 0) - (float) ($held[$branchId] ?? 0), 2),
        ])->values()->all();

        // Money still sitting on a branch-level suspense account: `mkopa:centralise-pending-receipts` moves it to HQ.
        $inBranchAccounts = round((float) $this->lines($companyId)->where('accounts.key', Account::Suspense->value)->whereNotNull('accounts.branch_id')
            ->sum(DB::raw('journal_lines.credit - journal_lines.debit')), 2);
        $centralised = abs($inBranchAccounts) < self::TOLERANCE;

        return $this->check('suspense', 'Suspense vs unallocated payments', $centralised ? self::INFO : self::WARN,
            $centralised
                ? 'Suspense per branch compared with pending teller receipts and unallocated payments (information).'
                : 'Branch-level suspense accounts still hold '.$this->money($inBranchAccounts).'; run mkopa:centralise-pending-receipts.',
            ['branches' => $rows, 'in_branch_accounts' => $inBranchAccounts]);
    }

    /**
     * @return Check
     */
    private function tellerCash(int $companyId): array
    {
        $ledger = round((float) $this->balancesBy($companyId, Account::TellerCash, 'branch_id')->sum(), 2);
        $pending = round((float) DB::table('payments')
            ->where('company_id', $companyId)
            ->where('source', Payment::SOURCE_TELLER)
            ->whereIn('status', PaymentStatus::values(PaymentStatus::PendingVerification, PaymentStatus::Deposited))
            ->sum('amount'), 2);
        $ok = abs($ledger - $pending) < self::TOLERANCE;

        return $this->check('teller_cash', 'Teller cash = receipts not yet banked', $ok ? self::PASS : self::WARN,
            $ok ? 'Teller cash agrees with receipts awaiting banking.' : 'Teller cash differs from receipts awaiting banking by '.$this->money($ledger - $pending).'.',
            ['ledger' => $ledger, 'pending_receipts' => $pending, 'difference' => round($ledger - $pending, 2)]);
    }

    /**
     * @return Check
     */
    private function staffPayable(int $companyId): array
    {
        $negative = $this->accountBalances($companyId, [Account::StaffPayable])->filter(fn (array $row): bool => $row['balance'] < -self::TOLERANCE)->values();

        return $this->check('staff_payable', 'Staff payable is not negative', $negative->isEmpty() ? self::PASS : self::FAIL,
            $negative->isEmpty() ? 'No staff payable account is overpaid.' : $negative->count().' staff payable accounts are negative (paid more than recognised).',
            ['negative' => $negative->take(self::SAMPLE)->all()]);
    }

    /**
     * @return Check
     */
    private function bank(int $companyId): array
    {
        $rows = $this->lines($companyId)
            ->where('accounts.key', Account::Bank->value)
            ->leftJoin('bank_accounts', 'bank_accounts.id', '=', 'accounts.bank_account_id')
            ->groupBy('accounts.bank_account_id', 'bank_accounts.company_id', 'bank_accounts.name')
            ->selectRaw('accounts.bank_account_id, bank_accounts.company_id AS bank_company_id, bank_accounts.name, SUM(journal_lines.debit) - SUM(journal_lines.credit) AS balance')
            ->get();

        $problems = [];
        $clearing = 0.0;
        foreach ($rows as $row) {
            if ($row->bank_account_id === null) {
                $clearing = round((float) $row->balance, 2);
            } elseif ($row->bank_company_id === null) {
                $problems[] = ['bank_account_id' => $row->bank_account_id, 'problem' => 'bank account does not exist'];
            } elseif ((int) $row->bank_company_id !== $companyId) {
                $problems[] = ['bank_account_id' => $row->bank_account_id, 'problem' => 'bank account belongs to another company'];
            }
        }

        $status = $problems !== [] ? self::FAIL : (abs($clearing) >= self::TOLERANCE ? self::INFO : self::PASS);

        return $this->check('bank', 'Bank ledger per bank account', $status,
            match ($status) {
                self::PASS => 'Every bank line belongs to one of the company\'s bank accounts.',
                self::INFO => 'Every bank line belongs to a company bank account or the bank clearing account; Bank clearing (provider receipts) holds '.$this->money($clearing).'.',
                default => count($problems).' bank ledger problems.',
            },
            [
                'accounts' => $rows->map(fn ($row): array => ['bank_account_id' => $row->bank_account_id, 'name' => $row->bank_account_id === null ? 'Bank clearing (provider receipts)' : $row->name, 'balance' => round((float) $row->balance, 2)])->values()->all(),
                'bank_clearing' => $clearing,
                'total' => round((float) $rows->sum('balance'), 2),
                'problems' => $problems,
            ]);
    }

    /**
     * @return Check
     */
    private function negativeFunds(int $companyId): array
    {
        $negative = $this->accountBalances($companyId, self::nonNegativeFunds())->filter(fn (array $row): bool => $row['balance'] < -self::TOLERANCE)->values();

        return $this->check('negative_funds', 'Fund accounts are not negative', $negative->isEmpty() ? self::PASS : self::FAIL,
            $negative->isEmpty() ? 'No fund account is overdrawn.' : $negative->count().' fund accounts are negative.',
            ['negative' => $negative->take(self::SAMPLE)->all()]);
    }

    /**
     * @return Check
     */
    private function missingTransactionType(int $companyId): array
    {
        $missing = DB::table('journal_entries')->where('company_id', $companyId)->whereNull('transaction_type');

        return $this->check('transaction_type', 'Entries carry a transaction type', self::INFO,
            (clone $missing)->count().' entries without a transaction type.',
            ['count' => (clone $missing)->count(), 'sample' => $missing->limit(self::SAMPLE)->get(['reference', 'description', 'source_type'])->map(fn ($row): array => (array) $row)->all()]);
    }

    /**
     * COMMISSION PAYABLE = Σ journal-posted commission allocations not yet paid by the commission payment flow (spec §22) nor
     * recognised in an approved payroll (legacy, D1).
     *
     * @return Check
     */
    private function commissionPayable(int $companyId): array
    {
        $ledger = $this->balance($companyId, Account::CommissionPayable);
        $pending = round((float) CommissionAllocation::query()
            ->where('company_id', $companyId)
            ->whereNotNull('journal_entry_id')
            ->where('payment_status', '!=', CommissionAllocation::STATUS_PAID)
            ->where(fn ($query) => $query->whereNull('payroll_run_id')->orWhereHas('payrollRun', fn ($run) => $run->where('status', PayrollRun::STATUS_DRAFT)))
            ->sum('amount'), 2);
        $ok = abs($ledger - $pending) < self::TOLERANCE;

        return $this->check('commission_payable', 'Commission payable = allocated commission not yet paid', $ok ? self::PASS : self::FAIL,
            $ok ? 'Commission payable agrees with the allocations awaiting payment.' : 'Commission payable differs from allocations awaiting payment by '.$this->money($ledger - $pending).'.',
            ['ledger' => $ledger, 'allocations_pending_payroll' => $pending, 'difference' => round($ledger - $pending, 2)]);
    }

    /**
     * PENALTY RECEIVABLE per branch = Σ unpaid, not waived penalties that were ACCRUED when charged (legacy D9 rows only — rule 14
     * charges no new accruals) of loans that are not written off.
     *
     * @return Check
     */
    private function penaltyReceivable(int $companyId): array
    {
        $ledger = $this->balancesBy($companyId, Account::PenaltyReceivable, 'branch_id');
        $open = DB::table('penalties')
            ->join('loans', 'loans.id', '=', 'penalties.loan_id')
            ->where('penalties.company_id', $companyId)
            ->whereNotNull('penalties.accrual_journal_entry_id')
            ->where('penalties.is_waived', false)
            ->where('loans.status', '!=', LoanStatus::WrittenOff->value)
            ->groupBy('penalties.branch_id')
            ->selectRaw('penalties.branch_id, SUM(penalties.amount - penalties.paid_amount) AS open_amount')
            ->pluck('open_amount', 'branch_id');

        $rows = $ledger->keys()->merge($open->keys())->unique()->map(fn ($branchId): array => [
            'branch_id' => $branchId === '' ? null : $branchId,
            'ledger' => round((float) ($ledger[$branchId] ?? 0), 2),
            'accrued_penalties_open' => round((float) ($open[$branchId] ?? 0), 2),
            'difference' => round((float) ($ledger[$branchId] ?? 0) - (float) ($open[$branchId] ?? 0), 2),
        ])->values()->all();
        $differences = array_filter($rows, fn (array $row): bool => abs($row['difference']) >= self::TOLERANCE);

        return $this->check('penalty_receivable', 'Penalty receivable = unpaid accrued penalties', $differences === [] ? self::PASS : self::FAIL,
            $differences === [] ? 'Penalty receivable agrees with the unpaid accrued penalties in every branch.' : count($differences).' branches differ from the unpaid accrued penalties.',
            ['branches' => $rows]);
    }

    /**
     * Legacy unaccrued penalties (cash basis): charged before accrual existed, never in PENALTY RECEIVABLE (information).
     *
     * @return Check
     */
    private function legacyPenalties(int $companyId): array
    {
        $row = DB::table('penalties')
            ->join('loans', 'loans.id', '=', 'penalties.loan_id')
            ->where('penalties.company_id', $companyId)
            ->whereNull('penalties.accrual_journal_entry_id')
            ->where('penalties.is_waived', false)
            ->where('loans.status', '!=', LoanStatus::WrittenOff->value)
            ->whereColumn('penalties.paid_amount', '<', 'penalties.amount')
            ->selectRaw('COUNT(*) AS penalties, COALESCE(SUM(penalties.amount - penalties.paid_amount), 0) AS open_amount')
            ->first();

        return $this->check('legacy_unaccrued_penalties', 'Legacy unaccrued penalties (cash basis)', self::INFO,
            (int) $row->penalties.' legacy penalties with '.$this->money((float) $row->open_amount).' unpaid are recognised only when paid (memo, not in PENALTY RECEIVABLE).',
            ['penalties' => (int) $row->penalties, 'open_amount' => round((float) $row->open_amount, 2)]);
    }

    /**
     * INTEREST RESERVE = reserve of standing repayments posted under the new rule + reserve of standing write-off recoveries
     * (C3 Option B) + legacy reserve moved out of interest income by month-end closings (D6).
     *
     * @return Check
     */
    private function interestReserve(int $companyId): array
    {
        $ledger = $this->balance($companyId, Account::InterestReserve);
        $repayments = round((float) DB::table('loan_transactions')
            ->where('company_id', $companyId)
            ->where('type', 'deposit')
            ->whereNull('reversed_at')
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('journal_lines')
                ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                ->whereColumn('journal_lines.journal_entry_id', 'loan_transactions.journal_entry_id')
                ->where('accounts.key', Account::InterestReserve->value))
            ->sum('reserve'), 2);
        $closings = round((float) $this->lines($companyId)
            ->where('accounts.key', Account::InterestReserve->value)
            ->where('journal_entries.transaction_type', TransactionType::MonthEndClosing->value)
            ->selectRaw('COALESCE(SUM(journal_lines.credit) - SUM(journal_lines.debit), 0) AS net')
            ->value('net'), 2);
        $recoveries = round((float) LoanRecovery::where('company_id', $companyId)->whereNull('reversed_at')->sum('reserve_amount'), 2);
        $expected = round($repayments + $recoveries + $closings, 2);
        $ok = abs($ledger - $expected) < self::TOLERANCE;

        return $this->check('interest_reserve', 'Interest reserve = reserve of repayments and recoveries + legacy reserve closed out of income', $ok ? self::PASS : self::FAIL,
            $ok ? 'Interest reserve agrees with the repayment and recovery reserve and month-end reclassifications.' : 'Interest reserve differs by '.$this->money($ledger - $expected).'.',
            ['ledger' => $ledger, 'repayment_reserve' => $repayments, 'recovery_reserve' => $recoveries, 'closing_reclassifications' => $closings, 'difference' => round($ledger - $expected, 2)]);
    }

    /**
     * REINVESTED PROFIT = Σ reinvestment of declarations made under the profit-allocation rule (D4); the principal moved in by
     * their reinvestment journals equals the same amount.
     *
     * @return Check
     */
    private function reinvestedProfit(int $companyId): array
    {
        $ledger = $this->balance($companyId, Account::ReinvestedProfit);
        $declared = round((float) DividendDeclaration::where('company_id', $companyId)->whereNotNull('allocation_rule')->sum('reinvest_amount'), 2);
        $moved = round((float) $this->lines($companyId)
            ->where('accounts.key', Account::Principal->value)
            ->where('journal_entries.transaction_type', TransactionType::ProfitReinvestment->value)
            ->selectRaw('COALESCE(SUM(journal_lines.debit) - SUM(journal_lines.credit), 0) AS net')
            ->value('net'), 2);
        $ok = abs($ledger - $declared) < self::TOLERANCE && abs($moved - $declared) < self::TOLERANCE;

        return $this->check('reinvested_profit', 'Reinvested profit = reinvestment declared and moved to principal', $ok ? self::PASS : self::FAIL,
            $ok ? 'Reinvested profit agrees with the declarations and the principal funded by them.' : 'Reinvested profit ('.$this->money($ledger).'), declared reinvestment ('.$this->money($declared).') and principal moved ('.$this->money($moved).') differ.',
            ['ledger' => $ledger, 'declared' => $declared, 'principal_moved' => $moved]);
    }

    /**
     * Profit chain of every closed period distributed under the profit-allocation rule: commission + pool + reinvestment =
     * commission + base ≤ distributable profit; one declaration per period; at most one standing allocation journal per branch
     * and period, whose credits equal the journal-posted allocation rows.
     *
     * @return Check
     */
    private function profitDistribution(int $companyId): array
    {
        $problems = [];
        $periods = [];

        foreach (DividendDeclaration::where('company_id', $companyId)->selectRaw('period, COUNT(*) AS declarations')->groupBy('period')->havingRaw('COUNT(*) > 1')->get() as $duplicate) {
            $problems[] = ['period' => substr((string) $duplicate->period, 0, 7), 'problem' => 'declared more than once'];
        }

        foreach (AccountingPeriod::where('company_id', $companyId)->closed()->orderBy('period_start')->get() as $period) {
            $label = $period->period_start->format('Y-m');
            $journals = $this->commission->allocationJournals($period);
            foreach ($journals->groupBy('branch_id')->filter(fn (Collection $group): bool => $group->count() > 1) as $branchId => $group) {
                $problems[] = ['period' => $label, 'problem' => "branch #{$branchId} has ".$group->count().' standing commission allocation journals'];
            }
            $credited = round((float) $journals->flatMap->lines->filter(fn ($line): bool => $line->account?->key === Account::CommissionPayable)->sum(fn ($line): float => (float) $line->credit - (float) $line->debit), 2);
            $posted = round((float) CommissionAllocation::where('accounting_period_id', $period->id)->whereNotNull('journal_entry_id')->sum('amount'), 2);
            if (abs($credited - $posted) >= self::TOLERANCE) {
                $problems[] = ['period' => $label, 'problem' => 'commission allocation journals ('.$this->money($credited).') differ from the allocation rows ('.$this->money($posted).')'];
            }

            $declaration = DividendDeclaration::where('company_id', $companyId)->whereDate('period', $period->period_start->toDateString())->whereNotNull('allocation_rule')->first();
            if ($declaration === null) {
                continue;
            }

            $distributable = round((float) $period->results()->sum('distributable_profit'), 2);
            $commission = $this->commission->commissionByBranch($period)['total'];
            $pool = (float) $declaration->dividend_amount;
            $reinvest = (float) $declaration->reinvest_amount;
            $base = (float) $declaration->base_amount;
            $row = ['period' => $label, 'distributable' => $distributable, 'commission' => $commission, 'declared_commission' => (float) $declaration->commission_amount, 'base' => $base, 'pool' => $pool, 'reinvestment' => $reinvest];
            $periods[] = $row;

            if (abs($pool + $reinvest - $base) >= self::TOLERANCE) {
                $problems[] = ['period' => $label, 'problem' => 'pool + reinvestment differ from the base'];
            }
            if ($commission + $base > $distributable + self::TOLERANCE) {
                $problems[] = ['period' => $label, 'problem' => 'commission + base exceed the distributable profit'];
            }
            if (abs($commission - (float) $declaration->commission_amount) >= self::TOLERANCE) {
                $problems[] = ['period' => $label, 'problem' => 'commission changed after the declaration'];
            }
            $retainedDebit = round((float) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                ->where('journal_lines.journal_entry_id', $declaration->journal_entry_id)->where('accounts.key', Account::RetainedProfit->value)->sum('journal_lines.debit'), 2);
            if (abs($retainedDebit - $base) >= self::TOLERANCE) {
                $problems[] = ['period' => $label, 'problem' => 'declaration journal does not debit the base from the Profit Account'];
            }
        }

        return $this->check('profit_distribution', 'Profit chain: commission + dividend + reinvestment ≤ distributable, distributed once', $problems === [] ? self::PASS : self::FAIL,
            $problems === [] ? count($periods).' distributed periods verified.' : count($problems).' profit distribution problems.',
            ['periods' => $periods, 'problems' => array_slice($problems, 0, self::SAMPLE)]);
    }

    /**
     * Recoveries after write-off (C3 Option B), reconciled per component against the net postings of LoanRecovery journals and
     * their reversals:
     *  - WRITE-OFF EXPENSE credited = Σ principal recovered;
     *  - PENALTY INCOME = Σ penalty recovered;
     *  - INTEREST INCOME = Σ (interest − reserve) of split recoveries + Σ amount of legacy interest-only recoveries;
     *  - INTEREST RESERVE = Σ reserve recovered; INSURANCE RESERVE = Σ insurance recovered;
     * every split recovery's components add up to its amount, every standing recovery has a standing journal and every reversed one
     * a reversed journal, no write-off is recovered beyond its total or (when its split is known) beyond any component, and loans
     * with recoveries are still written off. Legacy rows (component columns NULL) are accepted as interest-only.
     *
     * @return Check
     */
    private function loanRecoveries(int $companyId): array
    {
        $recoveryLines = fn (): Builder => $this->lines($companyId)
            ->where(fn (Builder $query) => $query->where('journal_entries.transaction_type', TransactionType::LoanRecovery->value)
                ->orWhereExists(fn (Builder $original) => $original->selectRaw('1')->from('journal_entries as recovery_entry')
                    ->whereColumn('recovery_entry.id', 'journal_entries.reversal_of_id')
                    ->where('recovery_entry.transaction_type', TransactionType::LoanRecovery->value)));
        $credited = fn (Account $account): float => round((float) $recoveryLines()->where('accounts.key', $account->value)
            ->selectRaw('COALESCE(SUM(journal_lines.credit) - SUM(journal_lines.debit), 0) AS net')->value('net'), 2);

        $standingRows = LoanRecovery::where('company_id', $companyId)->whereNull('reversed_at')->get();
        $legacy = $standingRows->filter(fn (LoanRecovery $recovery): bool => $recovery->isLegacy());
        $split = $standingRows->reject(fn (LoanRecovery $recovery): bool => $recovery->isLegacy());
        $expected = [
            'write_off_expense' => round((float) $split->sum('principal_amount'), 2),
            'penalty_income' => round((float) $split->sum('penalty_amount'), 2),
            'interest_income' => round((float) $split->sum(fn (LoanRecovery $recovery): float => (float) $recovery->interest_amount - (float) $recovery->reserve_amount) + (float) $legacy->sum('amount'), 2),
            'interest_reserve' => round((float) $split->sum('reserve_amount'), 2),
            'insurance_reserve' => round((float) $split->sum('insurance_amount'), 2),
        ];
        $ledger = [
            'write_off_expense' => $credited(Account::WriteOffExpense),
            'penalty_income' => $credited(Account::PenaltyIncome),
            'interest_income' => $credited(Account::InterestIncome),
            'interest_reserve' => $credited(Account::InterestReserve),
            'insurance_reserve' => $credited(Account::InsuranceReserve),
        ];

        $problems = [];
        foreach ($expected as $component => $amount) {
            if (abs($amount - $ledger[$component]) >= self::TOLERANCE) {
                $problems[] = ['component' => $component, 'problem' => 'recoveries '.$this->money($amount).' vs ledger '.$this->money($ledger[$component])];
            }
        }

        foreach (LoanRecovery::where('company_id', $companyId)->with('journalEntry.reversal')->get() as $recovery) {
            $standingJournal = $recovery->journalEntry !== null && $recovery->journalEntry->reversal === null;
            if ($recovery->reversed_at === null && ! $standingJournal) {
                $problems[] = ['recovery_id' => $recovery->id, 'problem' => 'standing recovery without a standing journal'];
            }
            if ($recovery->reversed_at !== null && $standingJournal) {
                $problems[] = ['recovery_id' => $recovery->id, 'problem' => 'reversed recovery whose journal was not reversed'];
            }
            if (! $recovery->isLegacy()) {
                $sum = round((float) $recovery->principal_amount + (float) $recovery->penalty_amount + (float) $recovery->interest_amount + (float) $recovery->insurance_amount, 2);
                if (abs($sum - (float) $recovery->amount) >= self::TOLERANCE) {
                    $problems[] = ['recovery_id' => $recovery->id, 'problem' => 'components '.$this->money($sum).' do not add up to the amount '.$this->money((float) $recovery->amount)];
                }
            }
        }

        $recoveries = app(LoanRecoveryService::class);
        $writeOffs = WriteOff::whereIn('id', $standingRows->pluck('write_off_id')->unique())->with('loan')->get();
        foreach ($writeOffs as $writeOff) {
            $rows = $standingRows->where('write_off_id', $writeOff->id);
            $recovered = round((float) $rows->sum('amount'), 2);
            if ($recovered > (float) $writeOff->amount + self::TOLERANCE) {
                $problems[] = ['loan_id' => (int) $writeOff->loan_id, 'problem' => 'recovered '.$this->money($recovered).' exceeds the write-off of '.$this->money((float) $writeOff->amount)];
            }
            $components = $recoveries->components($writeOff);
            if ($components['status'] === LoanRecoveryService::COMPONENTS_AMBIGUOUS) {
                if ($rows->contains(fn (LoanRecovery $recovery): bool => ! $recovery->isLegacy())) {
                    $problems[] = ['loan_id' => (int) $writeOff->loan_id, 'problem' => 'component recoveries on a write-off whose split is ambiguous'];
                }

                continue;
            }
            foreach (LoanRecovery::COMPONENTS as $component) {
                $byComponent = round((float) $rows->reject(fn (LoanRecovery $recovery): bool => $recovery->isLegacy())->sum($component.'_amount'), 2);
                if ($byComponent > (float) $components[$component] + self::TOLERANCE) {
                    $problems[] = ['loan_id' => (int) $writeOff->loan_id, 'problem' => $component.' recovered '.$this->money($byComponent).' exceeds the '.$component.' written off '.$this->money((float) $components[$component])];
                }
            }
        }

        $stillWrittenOff = DB::table('loan_recoveries')->join('loans', 'loans.id', '=', 'loan_recoveries.loan_id')
            ->where('loan_recoveries.company_id', $companyId)->where('loans.status', '!=', LoanStatus::WrittenOff->value)->distinct()->pluck('loans.id');
        foreach ($stillWrittenOff as $loanId) {
            $problems[] = ['loan_id' => (int) $loanId, 'problem' => 'loan with recoveries is no longer written off'];
        }

        $ok = $problems === [];

        return $this->check('loan_recoveries', 'Recoveries after write-off = component postings (write-off expense, penalty, interest + reserve, insurance), within the write-off', $ok ? self::PASS : self::FAIL,
            $ok ? $writeOffs->count().' write-offs with recoveries verified.' : count($problems).' recovery problems.',
            ['standing_recoveries' => round((float) $standingRows->sum('amount'), 2), 'legacy_interest_only' => round((float) $legacy->sum('amount'), 2), 'expected' => $expected, 'ledger' => $ledger, 'problems' => array_slice($problems, 0, self::SAMPLE)]);
    }

    /**
     * Rule 3: the interest reserve is never moved by the profit chain. No month-end closing, HQ hold, commission allocation,
     * payroll, dividend declaration, profit reinvestment or dividend payment entry (nor a reversal of one) touches the branch/HQ
     * RESERVE A/C, and none moves INTEREST RESERVE — except the month-end reclassification that credits legacy reserve still inside
     * interest income to INTEREST RESERVE (income → reserve equity; keeps it out of profit).
     *
     * @return Check
     */
    private function reserveUntouchedByProfitChain(int $companyId): array
    {
        $types = array_map(fn (TransactionType $type): string => $type->value, [
            TransactionType::MonthEndClosing, TransactionType::HqProfitHold, TransactionType::CommissionAllocation, TransactionType::CommissionPayment,
            TransactionType::PayrollRecognition, TransactionType::PayrollPayment, TransactionType::DividendDeclaration,
            TransactionType::ProfitReinvestment, TransactionType::DividendPayment,
        ]);
        $profitChain = fn (Builder $query) => $query->whereIn('journal_entries.transaction_type', $types)
            ->orWhereExists(fn (Builder $original) => $original->selectRaw('1')->from('journal_entries as original_entry')
                ->whereColumn('original_entry.id', 'journal_entries.reversal_of_id')
                ->whereIn('original_entry.transaction_type', $types));

        $rows = $this->lines($companyId)
            ->where($profitChain)
            ->where(fn (Builder $query) => $query
                ->whereIn('accounts.key', [Account::Reserve->value, Account::HqReserve->value])
                ->orWhere(fn (Builder $reserve) => $reserve->where('accounts.key', Account::InterestReserve->value)
                    ->where(fn (Builder $allowed) => $allowed->whereNull('journal_entries.transaction_type')
                        ->orWhere('journal_entries.transaction_type', '!=', TransactionType::MonthEndClosing->value)
                        ->orWhere('journal_lines.debit', '>', 0))))
            ->limit(self::SAMPLE)
            ->get(['journal_entries.reference', 'journal_entries.transaction_type', 'accounts.key AS account', 'journal_lines.debit', 'journal_lines.credit'])
            ->map(fn ($row): array => (array) $row)
            ->all();

        return $this->check('reserve_untouched_by_profit_chain', 'Interest reserve is never moved by the profit chain', $rows === [] ? self::PASS : self::FAIL,
            $rows === [] ? 'No closing, commission, payroll, dividend or reinvestment entry moves the RESERVE A/C or INTEREST RESERVE.' : count($rows).' profit-chain lines move the reserve.',
            ['lines' => $rows]);
    }

    /**
     * Rule 15: INSURANCE RESERVE only receives insurance. Every entry that moves INSURANCE RESERVE either collects insurance (the
     * same entry moves the INSURANCE A/C by the same amount — new collections, their reversals) or is a month-end closing that
     * moves legacy INSURANCE INCOME into it by the same amount. Legacy closings that closed insurance income to the Profit Account
     * (before insurance reserve existed) are reported for information.
     *
     * @return Check
     */
    private function insuranceReserve(int $companyId): array
    {
        $ledger = $this->balance($companyId, Account::InsuranceReserve);
        $perEntry = $this->lines($companyId)
            ->whereIn('accounts.key', [Account::InsuranceReserve->value, Account::Insurance->value, Account::InsuranceIncome->value])
            ->groupBy('journal_entries.id', 'journal_entries.reference', 'journal_entries.transaction_type')
            ->selectRaw('journal_entries.id, journal_entries.reference, journal_entries.transaction_type,
                SUM(CASE WHEN accounts.key = ? THEN journal_lines.credit - journal_lines.debit ELSE 0 END) AS reserve,
                SUM(CASE WHEN accounts.key = ? THEN journal_lines.debit - journal_lines.credit ELSE 0 END) AS fund,
                SUM(CASE WHEN accounts.key = ? THEN journal_lines.debit - journal_lines.credit ELSE 0 END) AS income_closed',
                [Account::InsuranceReserve->value, Account::Insurance->value, Account::InsuranceIncome->value])
            ->get();

        $collected = 0.0;
        $reclassified = 0.0;
        $legacyToProfit = 0.0;
        $problems = [];
        foreach ($perEntry as $entry) {
            $reserve = round((float) $entry->reserve, 2);
            $closing = $entry->transaction_type === TransactionType::MonthEndClosing->value;
            if ($closing) {
                $incomeClosed = round((float) $entry->income_closed, 2);
                if (abs($reserve) < self::TOLERANCE) {
                    $legacyToProfit = round($legacyToProfit + $incomeClosed, 2);
                } elseif (abs($reserve - $incomeClosed) >= self::TOLERANCE) {
                    $problems[] = ['reference' => $entry->reference, 'problem' => 'closing credits insurance reserve '.$this->money($reserve).' but closes insurance income '.$this->money($incomeClosed)];
                } else {
                    $reclassified = round($reclassified + $reserve, 2);
                }

                continue;
            }
            if (abs($reserve) < self::TOLERANCE) {
                continue;
            }
            if (abs($reserve - round((float) $entry->fund, 2)) >= self::TOLERANCE) {
                $problems[] = ['reference' => $entry->reference, 'problem' => 'insurance reserve '.$this->money($reserve).' without the same insurance collected ('.$this->money((float) $entry->fund).')'];
            }
            $collected = round($collected + $reserve, 2);
        }
        $difference = round($ledger - $collected - $reclassified, 2);
        $ok = $problems === [] && abs($difference) < self::TOLERANCE;

        return $this->check('insurance_reserve', 'Insurance reserve = insurance collected + legacy insurance income closed to it', $ok ? self::PASS : self::FAIL,
            $ok ? 'Insurance reserve holds only insurance; none of it reached profit.' : count($problems).' insurance reserve problems; difference '.$this->money($difference).'.',
            ['ledger' => $ledger, 'collected_to_reserve' => $collected, 'legacy_income_reclassified' => $reclassified, 'legacy_closed_to_profit' => $legacyToProfit, 'difference' => $difference, 'problems' => array_slice($problems, 0, self::SAMPLE)]);
    }

    /**
     * Correlated sub-query: a journal entry for the source that has not been reversed.
     */
    private function standingEntries(Builder $query, string $sourceType, string $sourceIdColumn, ?TransactionType $type = null): void
    {
        $query->selectRaw('1')->from('journal_entries as source_entry')
            ->where('source_entry.source_type', $sourceType)
            ->whereColumn('source_entry.source_id', $sourceIdColumn)
            ->whereNull('source_entry.reversal_of_id')
            ->when($type !== null, fn (Builder $inner) => $inner->where('source_entry.transaction_type', $type->value))
            ->whereNotExists(fn (Builder $reversal) => $reversal->selectRaw('1')->from('journal_entries as reversal_entry')->whereColumn('reversal_entry.reversal_of_id', 'source_entry.id'));
    }

    private function lines(int $companyId): Builder
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.company_id', $companyId);
    }

    private function balance(int $companyId, Account $account): float
    {
        return round((float) $this->balancesBy($companyId, $account, 'key')->sum(), 2);
    }

    /**
     * Normal-balance-aware balances of one account key grouped by an accounts column.
     *
     * @return Collection<string, float>
     */
    private function balancesBy(int $companyId, Account $account, string $column): Collection
    {
        $sign = $account->isDebitNormal() ? 'SUM(journal_lines.debit) - SUM(journal_lines.credit)' : 'SUM(journal_lines.credit) - SUM(journal_lines.debit)';

        return $this->lines($companyId)
            ->where('accounts.key', $account->value)
            ->groupBy('accounts.'.$column)
            ->selectRaw("accounts.{$column} AS grouping_key, {$sign} AS balance")
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->grouping_key => round((float) $row->balance, 2)]);
    }

    /**
     * Balance of every account row (branch / bank / employee scope) for the given keys.
     *
     * @param  list<Account>  $accounts
     * @return Collection<int, array{account_id: int, account: string, key: string, branch_id: ?int, bank_account_id: ?int, employee_id: ?int, balance: float}>
     */
    private function accountBalances(int $companyId, array $accounts): Collection
    {
        $keys = array_map(fn (Account $account): string => $account->value, $accounts);

        return $this->lines($companyId)
            ->whereIn('accounts.key', $keys)
            ->groupBy('accounts.id', 'accounts.key', 'accounts.name', 'accounts.branch_id', 'accounts.bank_account_id', 'accounts.employee_id')
            ->selectRaw('accounts.id, accounts.key, accounts.name, accounts.branch_id, accounts.bank_account_id, accounts.employee_id, SUM(journal_lines.debit) AS debits, SUM(journal_lines.credit) AS credits')
            ->get()
            ->map(function ($row): array {
                $net = (float) $row->debits - (float) $row->credits;

                return [
                    'account_id' => (int) $row->id,
                    'account' => $row->name,
                    'key' => $row->key,
                    'branch_id' => $row->branch_id,
                    'bank_account_id' => $row->bank_account_id,
                    'employee_id' => $row->employee_id,
                    'balance' => round(Account::from($row->key)->isDebitNormal() ? $net : -$net, 2),
                ];
            });
    }

    /**
     * @param  array<string, mixed>  $details
     * @return Check
     */
    private function check(string $key, string $title, string $status, string $message, array $details = []): array
    {
        return ['key' => $key, 'title' => $title, 'status' => $status, 'message' => $message, 'details' => $details];
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2);
    }
}
