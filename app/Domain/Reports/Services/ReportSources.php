<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Repayments\Enums\PaymentStatus;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\Enums\DpdBucket;
use App\Models\Branch;
use App\Models\JournalEntryLine;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * The single accessor layer every report goes through.
 *
 * Keeping the filtering in one place is what guarantees two reports over the
 * same data cannot disagree because one of them forgot a `deleted_at` check or
 * bucketed a loan a day differently. The frontend keeps the same discipline in
 * `lib/domain/reports/sources.ts`, and these are the same rules.
 *
 * Nothing here caches or stores. Every method is a query or a computation over
 * one, so a report is always a view of the data as it is now — §15.6's
 * "traceable to a specific computation timestamp".
 */
final class ReportSources
{
    public function __construct(private readonly TrialBalanceBuilder $trialBalance) {}

    // -----------------------------------------------------------------
    // Loans
    // -----------------------------------------------------------------

    /**
     * Every loan that has not been withdrawn, optionally scoped to a branch.
     *
     * @return EloquentCollection<int, Loan>
     */
    public function liveLoans(ReportFilters $filters): EloquentCollection
    {
        return Loan::query()
            ->with(['customer.category', 'branch', 'product', 'schedules'])
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->orderBy('id')
            ->get();
    }

    /**
     * Loans with money actually out — the only ones that carry a balance.
     *
     * Both conditions are needed. §6 puts the ledger entry at the disbursement
     * callback, so a loan without a disbursement date has been approved but
     * not funded; and a closed loan owes nothing even though it once did.
     *
     * @return EloquentCollection<int, Loan>
     */
    public function openBookLoans(ReportFilters $filters): EloquentCollection
    {
        return $this->liveLoans($filters)
            ->filter(fn (Loan $l): bool => $l->status->isOpenBook() && $l->disbursement_date !== null)
            ->values();
    }

    /**
     * What a loan still owes.
     *
     * Zero until the disbursement callback confirms — the same rule the Loans
     * module applies, so portfolio totals match the loan screen rather than
     * counting money that has not left the building.
     */
    public function loanOutstanding(Loan $loan): Money
    {
        if ($loan->disbursement_date === null) {
            return Money::zero();
        }

        return Money::sum($loan->schedules->map(fn (LoanSchedule $s): Money => $s->outstandingTotal()));
    }

    public function loanPaid(Loan $loan): Money
    {
        return Money::sum($loan->schedules->map(fn (LoanSchedule $s): Money => $s->totalPaid()));
    }

    public function loanDue(Loan $loan): Money
    {
        return Money::sum($loan->schedules->map(fn (LoanSchedule $s): Money => $s->totalDue()));
    }

    /** The unpaid portion of installments already past their due date. */
    public function loanOverdue(Loan $loan, ?Carbon $asOf = null): Money
    {
        $asOf ??= Date::now();

        return Money::sum(
            $loan->schedules
                ->filter(fn (LoanSchedule $s): bool => $s->outstandingTotal()->isPositive()
                    && $s->due_date->startOfDay()->lessThan($asOf->startOfDay()))
                ->map(fn (LoanSchedule $s): Money => $s->outstandingTotal()),
        );
    }

    /**
     * Days past due on the OLDEST still-unpaid installment.
     *
     * The oldest rather than the most recent: a loan that missed an
     * installment three months ago and has paid every one since is still three
     * months delinquent on that debt, and ageing it from the newest miss would
     * flatter it.
     */
    public function daysPastDue(Loan $loan, ?Carbon $asOf = null): int
    {
        $asOf ??= Date::now();

        $oldest = $loan->schedules
            ->filter(fn (LoanSchedule $s): bool => $s->outstandingTotal()->isPositive()
                && $s->due_date->startOfDay()->lessThan($asOf->startOfDay()))
            ->sortBy('due_date')
            ->first();

        if ($oldest === null) {
            return 0;
        }

        return max(0, (int) $oldest->due_date->startOfDay()->diffInDays($asOf->startOfDay(), false));
    }

    // -----------------------------------------------------------------
    // Payments
    // -----------------------------------------------------------------

    /**
     * Payments that have been collected and posted.
     *
     * The frontend's equivalent filters on `status === 'confirmed'`, because
     * its mock payments are received and confirmed in one step. This backend's
     * lifecycle does not collapse the two: a provider payment settles at
     * `allocated` and teller cash waits at `pending_verification` until a
     * deposit slip is reconciled (§7), so nothing ever reaches `confirmed`
     * until the bank-reconciliation endpoint ships.
     *
     * Anchoring on the ledger instead — a payment that produced a journal
     * entry and has not been reversed — is what keeps these reports
     * reconciling to the books, which is the requirement that matters. See
     * OSC-7.
     *
     * @return EloquentCollection<int, Payment>
     */
    public function collectedPayments(ReportFilters $filters): EloquentCollection
    {
        return Payment::query()
            ->with(['loan', 'customer', 'allocations'])
            ->whereNotNull('journal_entry_id')
            ->whereNotNull('loan_id')
            ->whereNotIn('status', [PaymentStatus::Reversed->value, PaymentStatus::DuplicateFlagged->value])
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->orderByDesc('received_at')
            ->get()
            ->filter(fn (Payment $p): bool => ! $filters->hasDateWindow() || $filters->covers($p->received_at))
            ->values();
    }

    // -----------------------------------------------------------------
    // Ledger
    // -----------------------------------------------------------------

    /**
     * The trial balance, optionally scoped to a branch.
     *
     * Recomputed from `journal_entry_lines` on every call by
     * TrialBalanceBuilder — never read from `account_balances`, which §2.7
     * calls a materialized cache. A financial report reading a summary it
     * cannot itself verify would not be evidence of anything.
     *
     * @return array{rows: list<array<string, mixed>>, totalDebits: string, totalCredits: string, balanced: bool}
     */
    public function trialBalance(ReportFilters $filters): array
    {
        return $this->trialBalance->build($filters->branchId, $filters->to);
    }

    /**
     * The net balance of every account of one type, on its normal side.
     */
    public function balanceOfType(ReportFilters $filters, AccountType $type): Money
    {
        return $this->balanceOfTypeFrom($this->trialBalance($filters), $type);
    }

    /**
     * Income and expense EARNED IN the filter window — the P&L figures.
     *
     * Distinct from `balanceOfType()`, and the difference is the whole point.
     * `balanceOfType()` reads the trial balance, which is cumulative to a date:
     * asking it for August returns everything since inception up to 31 August.
     * That is right for a balance sheet and wrong for a profit and loss
     * statement, which is asking what a period earned, not what has accumulated.
     *
     * Two rules, both of which the trial balance cannot express:
     *
     *   1. **Bounded on both sides.** `from` and `period` are honoured, not just
     *      `to`. Every P&L-shaped report used to pass filters that were silently
     *      half-ignored.
     *   2. **Trading entries only.** The month-end close (Decision Register D1)
     *      sweeps income and expense into Profit by posting to those accounts,
     *      so counting closing entries would report every closed period as
     *      having earned exactly nothing.
     *
     * @return array{Money, Money} income, expense — each netted on its own side
     */
    public function periodIncomeExpense(ReportFilters $filters): array
    {
        $rows = JournalEntryLine::query()
            ->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'journal_entry_lines.account_id')
            ->whereIn('coa.type', [AccountType::Income->value, AccountType::Expense->value])
            ->whereNotIn('je.source_type', JournalSourceType::periodClosingValues())
            ->when(
                $filters->branchId !== null,
                fn ($q) => $q->where('journal_entry_lines.branch_id', $filters->branchId),
            )
            ->when($filters->from !== null, fn ($q) => $q->whereDate('je.entry_date', '>=', $filters->from))
            ->when($filters->to !== null, fn ($q) => $q->whereDate('je.entry_date', '<=', $filters->to))
            ->when(
                $filters->period !== null,
                fn ($q) => $q->whereRaw("DATE_FORMAT(je.entry_date, '%Y-%m') = ?", [$filters->period]),
            )
            ->groupBy('coa.type')
            ->select('coa.type')
            ->selectRaw('SUM(journal_entry_lines.debit_amount) AS debit_total, SUM(journal_entry_lines.credit_amount) AS credit_total')
            ->get()
            ->keyBy('type');

        $income = $rows->get(AccountType::Income->value);
        $expense = $rows->get(AccountType::Expense->value);

        // Each netted on its own normal side, so a refunded fee reduces income
        // rather than appearing as an expense.
        return [
            Money::of((string) ($income->credit_total ?? '0.00'))
                ->subtract(Money::of((string) ($income->debit_total ?? '0.00'))),
            Money::of((string) ($expense->debit_total ?? '0.00'))
                ->subtract(Money::of((string) ($expense->credit_total ?? '0.00'))),
        ];
    }

    /**
     * @param array{rows: list<array<string, mixed>>, totalDebits: string, totalCredits: string, balanced: bool} $trialBalance
     */
    public function balanceOfTypeFrom(array $trialBalance, AccountType $type): Money
    {
        return Money::sum(
            collect($trialBalance['rows'])
                ->filter(fn (array $r): bool => $r['type'] === $type->value)
                ->map(fn (array $r): Money => Money::of((string) $r['balance'])),
        );
    }

    /**
     * Journal lines inside the filter window, joined to their entry so the
     * entry date — not the line — decides whether a line is in the period.
     *
     * @return EloquentCollection<int, JournalEntryLine>
     */
    public function journalLines(ReportFilters $filters): EloquentCollection
    {
        return JournalEntryLine::query()
            ->with(['account', 'entry'])
            ->when($filters->branchId !== null, fn ($q) => $q->where('journal_entry_lines.branch_id', $filters->branchId))
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->when($filters->from !== null, fn ($q) => $q->whereDate('journal_entries.entry_date', '>=', $filters->from))
            ->when($filters->to !== null, fn ($q) => $q->whereDate('journal_entries.entry_date', '<=', $filters->to))
            ->when(
                $filters->period !== null,
                fn ($q) => $q->whereRaw("DATE_FORMAT(journal_entries.entry_date, '%Y-%m') = ?", [$filters->period]),
            )
            ->orderBy('journal_entries.posted_at')
            ->select('journal_entry_lines.*')
            ->get();
    }

    // -----------------------------------------------------------------
    // Organization
    // -----------------------------------------------------------------

    /**
     * @return EloquentCollection<int, Branch>
     */
    public function branches(ReportFilters $filters): EloquentCollection
    {
        return Branch::query()
            ->when($filters->branchId !== null, fn ($q) => $q->where('id', $filters->branchId))
            ->orderBy('id')
            ->get();
    }

    public function headOffice(): ?Branch
    {
        return Branch::query()->where('is_head_office', true)->first();
    }

    // -----------------------------------------------------------------
    // Shared classification
    // -----------------------------------------------------------------

    /**
     * Which DPD bucket a loan falls in.
     *
     * Defined once, here, because a loan that landed in different buckets on
     * two screens would make both untrustworthy.
     */
    public function bucketFor(int $daysPastDue): DpdBucket
    {
        return DpdBucket::forDays($daysPastDue);
    }

    /**
     * What a loan is worth to the portfolio reports, as a plain row fragment.
     *
     * @return array{principal: Money, due: Money, paid: Money, outstanding: Money}
     */
    public function loanFigures(Loan $loan): array
    {
        return [
            'principal' => $loan->principal(),
            'due' => $this->loanDue($loan),
            'paid' => $this->loanPaid($loan),
            'outstanding' => $this->loanOutstanding($loan),
        ];
    }

    /** The statuses §15.6's Recovery report covers. */
    public function isRecoveryStatus(LoanStatus $status): bool
    {
        return in_array($status, [LoanStatus::Defaulted, LoanStatus::WrittenOff, LoanStatus::Recovered], true);
    }

    /**
     * A percentage of a whole, as a decimal string with three places.
     *
     * Percentages are ratios rather than money, so they are computed from the
     * two amounts' minor units and returned as a string like every other
     * numeric field — a JSON double would reintroduce exactly the imprecision
     * Money exists to avoid.
     */
    public function percentageOf(Money $part, Money $whole): string
    {
        if ($whole->minor === 0) {
            return '0.000';
        }

        $scaled = (int) round($part->minor * 100_000 / $whole->minor);

        return number_format($scaled / 1000, 3, '.', '');
    }
}
