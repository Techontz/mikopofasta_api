<?php

namespace App\Services;

use App\Enums\Account;
use App\Enums\TransactionType;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ExpenseType;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Double-entry general ledger — the single source of truth for every balance
 * (Documents: "LEDGER NDIO CHANZO CHA UKWELI").
 *
 * Rules enforced here:
 *  - every entry has debits equal to credits;
 *  - entries are never updated or deleted, only reversed;
 *  - balances are always computed from journal lines (real-time).
 *
 * A line is described as an array:
 *   ['account' => Account, 'debit' => float, 'credit' => float,
 *    'branch' => Branch|int|null, 'bank' => BankAccount|int|null,
 *    'employee' => Employee|int|null, 'expense_type' => ExpenseType|int|null]
 *
 * Every entry carries a {@see TransactionType} (the business event). Callers may pass it; otherwise it is
 * inferred centrally from the source model, description and accounts ({@see TransactionType::infer()}).
 */
class Ledger
{
    /**
     * @param  array<int, array{account: Account, debit?: float|int, credit?: float|int, branch?: Branch|int|null, bank?: BankAccount|int|null, employee?: Employee|int|null, expense_type?: ExpenseType|int|null}>  $lines
     */
    public function journal(
        Company|int $company,
        string $description,
        array $lines,
        ?Model $source = null,
        ?CarbonInterface $date = null,
        Branch|int|null $branch = null,
        Employee|int|null $employee = null,
        ?TransactionType $type = null,
    ): JournalEntry {
        $companyId = $this->id($company);
        $lines = array_values(array_filter($lines, fn (array $line): bool => round((float) ($line['debit'] ?? 0), 2) > 0 || round((float) ($line['credit'] ?? 0), 2) > 0));

        $debits = round(array_sum(array_map(fn (array $line): float => round((float) ($line['debit'] ?? 0), 2), $lines)), 2);
        $credits = round(array_sum(array_map(fn (array $line): float => round((float) ($line['credit'] ?? 0), 2), $lines)), 2);

        if ($lines === [] || abs($debits - $credits) > 0.001) {
            throw new InvalidArgumentException("Unbalanced journal entry \"{$description}\": debits {$debits} ≠ credits {$credits}.");
        }

        $this->assertPeriodOpen($companyId, $date ?? now());

        $type ??= TransactionType::infer(
            $source?->getMorphClass(),
            $description,
            array_values(array_map(fn (array $line): string => $line['account']->value, array_filter($lines, fn (array $line): bool => round((float) ($line['debit'] ?? 0), 2) > 0))),
            array_values(array_map(fn (array $line): string => $line['account']->value, array_filter($lines, fn (array $line): bool => round((float) ($line['credit'] ?? 0), 2) > 0))),
        );

        return DB::transaction(function () use ($companyId, $description, $lines, $source, $date, $branch, $employee, $type): JournalEntry {
            $entry = JournalEntry::create([
                'company_id' => $companyId,
                'branch_id' => $this->id($branch),
                'employee_id' => $this->id($employee) ?? auth()->id(),
                'reference' => $this->newReference(),
                'description' => $description,
                'transaction_type' => $type?->value,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'entry_date' => ($date ?? now())->toDateString(),
            ]);

            foreach ($lines as $line) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $this->account($companyId, $line['account'], $line['branch'] ?? null, $line['bank'] ?? null, $line['employee'] ?? null, $line['expense_type'] ?? null)->id,
                    'debit' => round((float) ($line['debit'] ?? 0), 2),
                    'credit' => round((float) ($line['credit'] ?? 0), 2),
                ]);
            }

            return $entry->load('lines');
        });
    }

    /**
     * Move money from one account to another: Dr $to, Cr $from. An optional charge is
     * taken from the source account into Bank Charges.
     *
     * @param  array{account: Account, branch?: Branch|int|null, bank?: BankAccount|int|null, employee?: Employee|int|null, expense_type?: ExpenseType|int|null}  $from
     * @param  array{account: Account, branch?: Branch|int|null, bank?: BankAccount|int|null, employee?: Employee|int|null, expense_type?: ExpenseType|int|null}  $to
     */
    public function transfer(Company|int $company, array $from, array $to, float $amount, string $description, ?Model $source = null, float $charge = 0, ?CarbonInterface $date = null, ?TransactionType $type = null): JournalEntry
    {
        $lines = [
            $to + ['debit' => $amount],
            $from + ['credit' => $amount + $charge],
        ];

        if ($charge > 0) {
            $lines[] = ['account' => Account::BankCharges, 'branch' => $from['branch'] ?? null, 'debit' => $charge];
        }

        return $this->journal($company, $description, $lines, $source, $date, $to['branch'] ?? $from['branch'] ?? null, type: $type);
    }

    /**
     * Opening balance for an asset account, funded from Capital (used for take-on balances and test fixtures).
     */
    public function openingBalance(
        Company|int $company,
        Account $account,
        float $amount,
        string $description = 'OPENING BALANCE',
        Branch|int|null $branch = null,
        ?Model $reference = null,
        BankAccount|int|null $bankAccount = null,
        ?CarbonInterface $date = null,
        ?TransactionType $type = null,
    ): JournalEntry {
        return $this->transfer($company, ['account' => Account::Capital], ['account' => $account, 'branch' => $branch, 'bank' => $bankAccount], $amount, $description, $reference, date: $date, type: $type ?? TransactionType::OpeningBalance);
    }

    /**
     * Post an opposite entry for an existing one. The original stays untouched.
     */
    public function reverse(JournalEntry $entry, string $reason): JournalEntry
    {
        if ($entry->reversal()->exists()) {
            throw new InvalidArgumentException("Journal entry {$entry->reference} has already been reversed.");
        }
        if ($entry->reversal_of_id !== null) {
            throw new InvalidArgumentException('A reversal entry cannot itself be reversed.');
        }

        $this->assertPeriodOpen((int) $entry->company_id, now());

        return DB::transaction(function () use ($entry, $reason): JournalEntry {
            $reversal = JournalEntry::create([
                'company_id' => $entry->company_id,
                'branch_id' => $entry->branch_id,
                'employee_id' => auth()->id(),
                'reference' => $this->newReference(),
                'description' => 'REVERSAL: '.$entry->description,
                'transaction_type' => TransactionType::Reversal->value,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'entry_date' => now()->toDateString(),
                'reversal_of_id' => $entry->id,
                'reversal_reason' => $reason,
            ]);

            foreach ($entry->lines as $line) {
                JournalLine::create([
                    'journal_entry_id' => $reversal->id,
                    'account_id' => $line->account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                ]);
            }

            return $reversal->load('lines');
        });
    }

    /**
     * Normal-balance-aware balance of an account (or all branches of it).
     */
    public function balance(
        Company|int $company,
        Account $account,
        Branch|int|null $branch = null,
        BankAccount|int|null $bankAccount = null,
        ?CarbonInterface $until = null,
        bool $allBranches = false,
        Employee|int|null $employee = null,
        ?CarbonInterface $from = null,
    ): float {
        $query = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('accounts.company_id', $this->id($company))
            ->where('accounts.key', $account->value);

        if (! $allBranches) {
            $branchId = $this->id($branch);
            $branchId === null ? $query->whereNull('accounts.branch_id') : $query->where('accounts.branch_id', $branchId);
        }
        if ($bankAccount !== null) {
            $query->where('accounts.bank_account_id', $this->id($bankAccount));
        }
        if ($employee !== null) {
            $query->where('accounts.employee_id', $this->id($employee));
        }
        if ($from !== null) {
            $query->whereDate('journal_entries.entry_date', '>=', $from->toDateString());
        }
        if ($until !== null) {
            $query->whereDate('journal_entries.entry_date', '<=', $until->toDateString());
        }

        $totals = $query->selectRaw('COALESCE(SUM(journal_lines.debit), 0) AS debits, COALESCE(SUM(journal_lines.credit), 0) AS credits')->first();
        $net = (float) $totals->debits - (float) $totals->credits;

        return round($account->isDebitNormal() ? $net : -$net, 2);
    }

    /**
     * Total increase ($inflow) or decrease of an account within a date range.
     */
    public function movement(Company|int $company, Account $account, CarbonInterface $from, CarbonInterface $to, bool $inflow = true, ?int $branchId = null): float
    {
        $query = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('accounts.company_id', $this->id($company))
            ->where('accounts.key', $account->value)
            ->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()]);

        if ($branchId !== null) {
            $query->where('accounts.branch_id', $branchId);
        }

        $increaseColumn = $account->isDebitNormal() ? 'debit' : 'credit';
        $decreaseColumn = $account->isDebitNormal() ? 'credit' : 'debit';

        return round((float) $query->sum('journal_lines.'.($inflow ? $increaseColumn : $decreaseColumn)), 2);
    }

    /**
     * Resolve (creating on first use) the chart-of-accounts row for a key and scope.
     */
    public function account(
        Company|int $company,
        Account $key,
        Branch|int|null $branch = null,
        BankAccount|int|null $bank = null,
        Employee|int|null $employee = null,
        ExpenseType|int|null $expenseType = null,
    ): LedgerAccount {
        $scope = [
            'company_id' => $this->id($company),
            'key' => $key->value,
            'branch_id' => $this->id($branch),
            'bank_account_id' => $this->id($bank),
            'employee_id' => $this->id($employee),
            'expense_type_id' => $this->id($expenseType),
        ];

        $existing = LedgerAccount::query()->where(function ($query) use ($scope): void {
            foreach ($scope as $column => $value) {
                $value === null ? $query->whereNull($column) : $query->where($column, $value);
            }
        })->first();

        return $existing ?? LedgerAccount::create($scope + [
            'code' => $key->code(),
            'name' => $key->label(),
            'type' => $key->type(),
        ]);
    }

    /**
     * Closed accounting periods are locked: no entry may be dated inside one. Corrections of a
     * closed period are reversals, which always post on today's date.
     *
     * @throws ValidationException
     */
    public function assertPeriodOpen(int $companyId, CarbonInterface $date): void
    {
        $closed = AccountingPeriod::query()
            ->where('company_id', $companyId)
            ->closed()
            ->whereDate('period_start', '<=', $date->toDateString())
            ->whereDate('period_end', '>=', $date->toDateString())
            ->first();

        if ($closed !== null) {
            throw ValidationException::withMessages([
                'entry_date' => "The accounting period {$closed->period_start->format('Y-m')} is closed; entries dated {$date->toDateString()} are not allowed.",
            ]);
        }
    }

    private function newReference(): string
    {
        return 'JE'.now()->format('ymd').strtoupper(Str::random(8));
    }

    private function id(Model|int|null $value): ?int
    {
        return $value instanceof Model ? (int) $value->getKey() : $value;
    }
}
