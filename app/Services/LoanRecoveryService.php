<?php

namespace App\Services;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanRecovery;
use App\Models\LoanTransaction;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\WriteOff;
use App\Services\Approvals\SegregationOfDuties;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recovery after write-off — C3 Option B (spec §9 income sources, §20–22 traceability and reversal, §30 idempotency).
 *
 * A later payment on a written-off loan is a NEW recovery transaction. It never reopens the loan, never changes its outstanding
 * balance, never creates a loan and never changes the write-off (its WriteOff row, its journal and the loan status stay as
 * posted). Only CONFIRMED money is recovered: branch / teller receipts wait for Finance ({@see PaymentService}); Finance entries,
 * Finance suspense allocations and provider-confirmed webhooks record the recovery.
 *
 *  - Allocation Principal → Penalty → Interest → Insurance, each capped at the component outstanding AT WRITE-OFF less the
 *    standing recoveries of that component ({@see components()}). A write-off whose split cannot be established reliably is
 *    AMBIGUOUS and takes no recovery until a controlled adjustment sets its snapshot.
 *  - Posting, one balanced journal per recovery on the loan's branch, transaction type LoanRecovery (r = company reserve_percent):
 *      principal P: Dr PRINCIPAL A/C / Cr WRITE-OFF EXPENSE (contra expense — never RECOVERED LOANS income)
 *      penalty N:   Dr PENALTY A/C / Cr PENALTY INCOME
 *      interest I:  Dr INTEREST A/C I·(1−r) + Dr RESERVE A/C I·r / Cr INTEREST INCOME I·(1−r) + Cr INTEREST RESERVE I·r
 *      insurance S: Dr INSURANCE A/C / Cr INSURANCE RESERVE
 *    No fee line. Money from suspense is moved first: Dr SUSPENSE / Cr BANK.
 *  - Legacy recoveries (component columns NULL, posted Dr INTEREST A/C / Cr INTEREST INCOME for the full amount) stay as booked
 *    and count as recovered interest.
 *  - Duplicates: one standing recovery per payment (unique loan_recoveries.standing_payment_id, cleared on reversal), webhook
 *    (channel, transaction_id).
 *  - Reversal (newest first): Ledger::reverse() mirror of the recovery journal, the recovery marked reversed (never deleted), the
 *    money back to SUSPENSE unallocated (Dr BANK / Cr SUSPENSE). Blocked when already reversed, not newest, its closed period was
 *    distributed / commission calculated, or a branch fund account no longer holds the money.
 */
class LoanRecoveryService
{
    public const NOT_RECOVERED = 'NOT_RECOVERED';

    public const PARTIALLY_RECOVERED = 'PARTIALLY_RECOVERED';

    public const FULLY_RECOVERED = 'FULLY_RECOVERED';

    /** Component split stored on the write-off when it was posted. */
    public const COMPONENTS_SNAPSHOT = 'snapshot';

    /** Component split of an older write-off reproduced exactly from the loan's own records. */
    public const COMPONENTS_DERIVED = 'derived';

    /** Component split of an older write-off that cannot be established reliably: no recovery until it is set. */
    public const COMPONENTS_AMBIGUOUS = 'ambiguous';

    public const AMBIGUOUS_MESSAGE = 'The component split at write-off is unknown for this loan; record a controlled adjustment / set the write-off snapshot before recording a recovery.';

    private const TOLERANCE = 0.005;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly LoanService $loans,
    ) {}

    /**
     * Principal / penalty / interest / insurance outstanding when the loan was written off.
     *
     * @return array{status: string, reason: string|null, principal: float|null, penalty: float|null, interest: float|null, insurance: float|null}
     */
    public function components(WriteOff $writeOff): array
    {
        $ambiguous = fn (string $reason): array => ['status' => self::COMPONENTS_AMBIGUOUS, 'reason' => $reason, 'principal' => null, 'penalty' => null, 'interest' => null, 'insurance' => null];

        if ($writeOff->principal_amount !== null && $writeOff->interest_amount !== null && $writeOff->penalty_amount !== null && $writeOff->insurance_amount !== null) {
            return [
                'status' => self::COMPONENTS_SNAPSHOT,
                'reason' => null,
                'principal' => (float) $writeOff->principal_amount,
                'penalty' => (float) $writeOff->penalty_amount,
                'interest' => (float) $writeOff->interest_amount,
                'insurance' => (float) $writeOff->insurance_amount,
            ];
        }
        if ($writeOff->principal_amount === null) {
            return $ambiguous('The write-off predates component tracking and does not record the principal written off.');
        }
        if ((float) $writeOff->recovered_amount > 0.004) {
            return $ambiguous('The write-off carries a legacy recovered amount without a component split.');
        }

        $loan = $writeOff->loan ?? Loan::findOrFail($writeOff->loan_id);
        $cutoff = $writeOff->written_off_on->toDateString();
        $paid = LoanTransaction::where('loan_id', $loan->id)->where('type', 'deposit')->whereNull('reversed_at')->whereDate('transaction_date', '<=', $cutoff)
            ->selectRaw('COALESCE(SUM(principal),0) p, COALESCE(SUM(interest),0) i, COALESCE(SUM(insurance),0) s')->first();
        $principal = max(0.0, round((float) $loan->amount_approved - (float) $loan->opening_paid_principal - (float) $paid->p, 2));
        $interest = max(0.0, round((float) $loan->interest_amount - (float) $paid->i, 2));
        $insurance = max(0.0, round((float) $loan->insurance - (float) $paid->s, 2));
        $penalty = round((float) $writeOff->amount - $principal - $interest - $insurance, 2);
        $openPenalties = round((float) Penalty::where('loan_id', $loan->id)->where('is_waived', false)->whereDate('penalty_date', '<=', $cutoff)
            ->selectRaw('COALESCE(SUM(amount - paid_amount),0) v')->value('v'), 2);

        if (abs($principal - (float) $writeOff->principal_amount) >= self::TOLERANCE || $penalty < -self::TOLERANCE || abs($penalty - $openPenalties) >= self::TOLERANCE) {
            return $ambiguous('The loan\'s repayments and penalties do not reproduce the written-off total of TZS '.money($writeOff->amount).'.');
        }

        return ['status' => self::COMPONENTS_DERIVED, 'reason' => null, 'principal' => $principal, 'penalty' => max(0.0, $penalty), 'interest' => $interest, 'insurance' => $insurance];
    }

    /**
     * Written off, recovered (standing recoveries) and unrecovered amounts of a loan, the derived recovery status, the split per
     * component (null when ambiguous) and branch money for the loan still awaiting Finance.
     *
     * @return array{write_off_id: int|null, written_off: float, recovered: float, unrecovered: float, status: string, components_status: string|null, ambiguous_reason: string|null, components: array<string, array{written_off: float, recovered: float, remaining: float}>|null, pending: float}
     */
    public function position(Loan $loan): array
    {
        $writeOff = WriteOff::where('loan_id', $loan->id)->first();
        if ($writeOff === null) {
            return ['write_off_id' => null, 'written_off' => 0.0, 'recovered' => 0.0, 'unrecovered' => 0.0, 'status' => self::NOT_RECOVERED, 'components_status' => null, 'ambiguous_reason' => null, 'components' => null, 'pending' => 0.0];
        }

        $writtenOff = round((float) $writeOff->amount, 2);
        $standing = LoanRecovery::where('loan_id', $loan->id)->whereNull('reversed_at')->get();
        $recovered = round((float) $standing->sum('amount'), 2);
        $unrecovered = max(0.0, round($writtenOff - $recovered, 2));
        $split = $this->components($writeOff);
        $components = null;

        if ($split['status'] !== self::COMPONENTS_AMBIGUOUS) {
            $byComponent = $this->recoveredByComponent($split, $standing);
            $components = [];
            foreach (LoanRecovery::COMPONENTS as $component) {
                $components[$component] = [
                    'written_off' => (float) $split[$component],
                    'recovered' => $byComponent[$component],
                    'remaining' => max(0.0, round((float) $split[$component] - $byComponent[$component], 2)),
                ];
            }
            $unrecovered = round(array_sum(array_column($components, 'remaining')), 2);
        }

        return [
            'write_off_id' => $writeOff->id,
            'written_off' => $writtenOff,
            'recovered' => $recovered,
            'unrecovered' => $unrecovered,
            'status' => match (true) {
                $recovered <= 0.004 => self::NOT_RECOVERED,
                $unrecovered <= 0.004 => self::FULLY_RECOVERED,
                default => self::PARTIALLY_RECOVERED,
            },
            'components_status' => $split['status'],
            'ambiguous_reason' => $split['reason'],
            'components' => $components,
            'pending' => round((float) Payment::where('loan_id', $loan->id)->whereIn('status', PaymentStatus::values(...PaymentStatus::awaitingFinance()))->sum('amount'), 2),
        ];
    }

    /**
     * What can still be recovered on the loan now: the unrecovered write-off, or 0 when its split is ambiguous.
     */
    public function recoverable(Loan $loan): float
    {
        $position = $this->position($loan);

        return $position['components_status'] === self::COMPONENTS_AMBIGUOUS ? 0.0 : $position['unrecovered'];
    }

    /**
     * Split an amount across the remaining components in the order Principal → Penalty → Interest → Insurance.
     *
     * @param  array<string, array{written_off: float, recovered: float, remaining: float}>  $components
     * @return array{principal: float, penalty: float, interest: float, insurance: float, excess: float}
     */
    public function allocate(array $components, float $amount): array
    {
        $remaining = round($amount, 2);
        $allocation = [];
        foreach (LoanRecovery::COMPONENTS as $component) {
            $portion = round(min($remaining, $components[$component]['remaining']), 2);
            $allocation[$component] = $portion;
            $remaining = round($remaining - $portion, 2);
        }

        return $allocation + ['excess' => max(0.0, $remaining)];
    }

    /**
     * Record confirmed money recovered on a written-off loan (see class docs). The loan row is locked and the position recomputed
     * inside the transaction.
     *
     * @throws ValidationException
     */
    public function record(Loan $loan, float $amount, CarbonImmutable $date, string $method, ?string $reference, ?Employee $employee, ?Payment $payment = null): LoanRecovery
    {
        return DB::transaction(function () use ($loan, $amount, $date, $method, $reference, $employee, $payment): LoanRecovery {
            $loan = Loan::whereKey($loan->id)->lockForUpdate()->with('writeOff')->firstOrFail();
            $amount = round($amount, 2);

            if ($loan->status !== LoanStatus::WrittenOff || $loan->writeOff === null) {
                throw ValidationException::withMessages(['amount' => 'Recoveries can only be recorded on a written-off loan.']);
            }
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
            }

            $before = $this->position($loan);
            if ($before['components_status'] === self::COMPONENTS_AMBIGUOUS) {
                throw ValidationException::withMessages(['amount' => self::AMBIGUOUS_MESSAGE]);
            }
            if ($before['unrecovered'] <= 0.004) {
                throw ValidationException::withMessages(['amount' => 'This written-off loan is already fully recovered.']);
            }
            if ($amount > $before['unrecovered'] + 0.004) {
                throw ValidationException::withMessages(['amount' => 'Amount exceeds the unrecovered write-off balance of '.money($before['unrecovered']).'.']);
            }
            if ($payment !== null && LoanRecovery::where('payment_id', $payment->id)->whereNull('reversed_at')->exists()) {
                throw ValidationException::withMessages(['amount' => "The payment {$payment->receipt_number} already holds a standing recovery; one payment records one recovery."]);
            }

            $split = $this->allocate($before['components'], $amount);
            $reservePercent = (float) Company::whereKey($loan->company_id)->value('reserve_percent');
            $reserve = round($split['interest'] * $reservePercent / 100, 2);
            $branch = $loan->branch_id;

            $recovery = LoanRecovery::create([
                'company_id' => $loan->company_id,
                'branch_id' => $branch,
                'loan_id' => $loan->id,
                'write_off_id' => $loan->writeOff->id,
                'customer_id' => $loan->customer_id,
                'payment_id' => $payment?->id,
                'standing_payment_id' => $payment?->id,
                'employee_id' => $employee?->id,
                'amount' => $amount,
                'principal_amount' => $split['principal'],
                'penalty_amount' => $split['penalty'],
                'interest_amount' => $split['interest'],
                'reserve_amount' => $reserve,
                'insurance_amount' => $split['insurance'],
                'method' => substr(strtoupper($method), 0, 20),
                'reference' => $reference,
                'recovered_on' => $date->toDateString(),
            ]);

            $entry = $this->ledger->journal($loan->company_id, 'LOAN RECOVERY '.$loan->loan_number, [
                ['account' => Account::Principal, 'debit' => $split['principal']],
                ['account' => Account::WriteOffExpense, 'branch' => $branch, 'credit' => $split['principal']],
                ['account' => Account::Penalty, 'branch' => $branch, 'debit' => $split['penalty']],
                ['account' => Account::PenaltyIncome, 'branch' => $branch, 'credit' => $split['penalty']],
                ['account' => Account::Interest, 'branch' => $branch, 'debit' => $split['interest'] - $reserve],
                ['account' => Account::Reserve, 'branch' => $branch, 'debit' => $reserve],
                ['account' => Account::InterestIncome, 'branch' => $branch, 'credit' => $split['interest'] - $reserve],
                ['account' => Account::InterestReserve, 'branch' => $branch, 'credit' => $reserve],
                ['account' => Account::Insurance, 'branch' => $branch, 'debit' => $split['insurance']],
                ['account' => Account::InsuranceReserve, 'branch' => $branch, 'credit' => $split['insurance']],
            ], $recovery, $date, $branch, $employee, TransactionType::LoanRecovery);
            $recovery->forceFill(['journal_entry_id' => $entry->id])->save();

            $after = $this->position($loan);
            $components = ['principal' => $split['principal'], 'penalty' => $split['penalty'], 'interest' => $split['interest'], 'reserve' => $reserve, 'insurance' => $split['insurance']];
            AuditLog::create([
                'company_id' => $loan->company_id,
                'employee_id' => $employee?->id,
                'action' => 'LoanRecovery.recorded',
                'auditable_type' => $recovery->getMorphClass(),
                'auditable_id' => $recovery->id,
                'after' => $recovery->only(['amount', 'method', 'reference', 'recovered_on']) + $components + ['journal_reference' => $entry->reference],
                'context' => ['loan_id' => $loan->id, 'write_off_id' => $loan->writeOff->id, 'payment_id' => $payment?->id, 'recovery_status' => $after['status'], 'unrecovered' => $after['unrecovered']],
                'ip_address' => request()?->ip(),
            ]);
            app(LoanWorkflow::class)->record($loan, 'RECOVERY_RECORDED', $loan->status, $employee, [
                'recovery_id' => $recovery->id,
                'amount' => $amount,
                ...$components,
                'journal_reference' => $entry->reference,
                'recovery_status' => $after['status'],
            ]);

            return $recovery;
        });
    }

    /**
     * Why a recovery cannot be reversed now, or null when it can. reverse() enforces exactly these checks.
     */
    public function reversalBlocker(LoanRecovery $recovery): ?string
    {
        if ($recovery->reversed_at !== null) {
            return 'This recovery has already been reversed.';
        }

        $later = LoanRecovery::where('loan_id', $recovery->loan_id)->whereNull('reversed_at')->where('id', '>', $recovery->id)->latest('id')->first();
        if ($later !== null) {
            return 'A later recovery of TZS '.money($later->amount).' on '.$later->recovered_on->toDateString().' exists; reverse recoveries newest first.';
        }

        $entry = $recovery->journal_entry_id !== null ? JournalEntry::find($recovery->journal_entry_id) : null;
        if ($entry === null) {
            return 'This recovery has no ledger posting to reverse.';
        }
        if ($entry->reversal()->exists()) {
            return "The journal entry {$entry->reference} of this recovery was already reversed directly in the ledger; the recovery needs a manual correction.";
        }
        if (($blocker = $this->loans->distributedPeriodBlocker((int) $recovery->company_id, CarbonImmutable::parse($entry->entry_date), 'recovery')) !== null) {
            return $blocker;
        }

        $payment = $recovery->payment_id !== null ? Payment::find($recovery->payment_id) : null;
        if ($payment !== null && in_array($payment->status, [PaymentStatus::Refunded, PaymentStatus::Rejected], true)) {
            return "The payment {$payment->receipt_number} of this recovery is {$payment->status->value}; the money cannot be returned to suspense.";
        }

        $funds = [Account::Principal, Account::Interest, Account::Reserve, Account::Penalty, Account::Insurance];
        foreach ($entry->lines()->with('account')->get() as $line) {
            $key = $line->account?->key;
            if (in_array($key, $funds, true) && (float) $line->debit > 0) {
                $available = $this->ledger->balance((int) $recovery->company_id, $key, $line->account->branch_id);
                if ($available + self::TOLERANCE < (float) $line->debit) {
                    return 'The '.$key->label().' no longer holds the recovered money (available TZS '.money($available).', required TZS '.money($line->debit).').';
                }
            }
        }

        return null;
    }

    /**
     * Why the viewer cannot reverse this recovery now, or null when they can: the business blockers
     * ({@see reversalBlocker()}) and rule 6 — the employee who posted the recovery journal does not reverse it.
     */
    public function reverseBlockedReason(LoanRecovery $recovery, ?Employee $viewer): ?string
    {
        $reason = $this->reversalBlocker($recovery);
        if ($reason === null && $viewer !== null) {
            $entry = $recovery->relationLoaded('journalEntry') ? $recovery->journalEntry : JournalEntry::find($recovery->journal_entry_id);
            $reason = app(SegregationOfDuties::class)->reverseBlockedReason($entry, $viewer);
        }

        return $reason;
    }

    /**
     * Reverse a recovery (see class docs) in one transaction with the loan and recovery rows locked.
     *
     * @return array{recovery: LoanRecovery, reversal: JournalEntry, payment: Payment, closed_period: string|null}
     *
     * @throws ValidationException
     */
    public function reverse(LoanRecovery $recovery, string $reason, Employee $employee): array
    {
        return DB::transaction(function () use ($recovery, $reason, $employee): array {
            $loan = Loan::whereKey($recovery->loan_id)->lockForUpdate()->firstOrFail();
            $recovery = LoanRecovery::whereKey($recovery->id)->lockForUpdate()->firstOrFail();

            if (($blocker = $this->reversalBlocker($recovery)) !== null) {
                throw ValidationException::withMessages(['reason' => $blocker]);
            }

            $entry = JournalEntry::with('lines')->findOrFail($recovery->journal_entry_id);
            app(SegregationOfDuties::class)->assertCanReverse($entry, $employee);
            $closedPeriod = $this->loans->closedPeriodOn((int) $loan->company_id, CarbonImmutable::parse($entry->entry_date));
            $reversal = $this->ledger->reverse($entry, 'RECOVERY REVERSED: '.$reason);

            $recovery->update([
                'standing_payment_id' => null,
                'reversed_at' => now(),
                'reversed_by' => $employee->id,
                'reversal_reason' => $reason,
                'reversal_journal_entry_id' => $reversal->id,
            ]);

            $payments = app(PaymentService::class);
            $allocation = $recovery->paymentAllocation()->whereNull('reversed_at')->first();
            $payment = $allocation !== null
                ? $payments->returnReversedRepaymentToSuspense($allocation, $employee, 'RECOVERY')
                : $payments->holdReversedMoney($loan, (float) $recovery->amount, (string) $recovery->method, 'Reversed recovery of written-off loan '.$loan->loan_number.' dated '.$recovery->recovered_on->toDateString(), $employee);

            $position = $this->position($loan);
            AuditLog::create([
                'company_id' => $loan->company_id,
                'employee_id' => $employee->id,
                'action' => 'LoanRecovery.reversed',
                'auditable_type' => $recovery->getMorphClass(),
                'auditable_id' => $recovery->id,
                'before' => ['reversed_at' => null],
                'after' => ['reversed_at' => $recovery->reversed_at?->toIso8601String(), 'reversal_journal_entry_id' => $reversal->id],
                'context' => ['reason' => $reason, 'payment_id' => $payment->id, 'recovery_status' => $position['status']] + $recovery->componentAmounts(),
                'ip_address' => request()?->ip(),
            ]);
            app(LoanWorkflow::class)->record($loan, 'RECOVERY_REVERSED', $loan->status, $employee, [
                'recovery_id' => $recovery->id,
                'amount' => (float) $recovery->amount,
                ...$recovery->componentAmounts(),
                'reason' => $reason,
                'journal_reference' => $entry->reference,
                'reversal_reference' => $reversal->reference,
                'returned_to_suspense' => $payment->receipt_number,
                'closed_period_adjustment' => $closedPeriod?->period_start->format('Y-m'),
            ]);

            return ['recovery' => $recovery, 'reversal' => $reversal, 'payment' => $payment, 'closed_period' => $closedPeriod?->period_start->format('Y-m')];
        });
    }

    /**
     * Presentation of one recovery (loan detail and recovery list).
     *
     * @return array<string, mixed>
     */
    public function present(LoanRecovery $recovery, ?Employee $viewer): array
    {
        $blocker = $this->reverseBlockedReason($recovery, $viewer);

        return [
            'id' => $recovery->id,
            'date' => $recovery->recovered_on?->toDateString(),
            'amount' => (float) $recovery->amount,
            'legacy' => $recovery->isLegacy(),
            ...$recovery->componentAmounts(),
            'method' => $recovery->method,
            'reference' => $recovery->reference,
            'receipt_number' => $recovery->payment?->receipt_number,
            'employee' => $recovery->employee?->full_name,
            'journal_reference' => $recovery->journalEntry?->reference,
            'reversed' => $recovery->reversed_at !== null,
            'reversed_at' => $recovery->reversed_at?->toDateTimeString(),
            'reversed_by' => $recovery->reverser?->full_name,
            'reversal_reason' => $recovery->reversal_reason,
            'reversal_reference' => $recovery->reversalJournalEntry?->reference,
            'can_reverse' => $blocker === null,
            'reverse_blocked_reason' => $blocker,
        ];
    }

    /**
     * Standing recoveries per component. A legacy (interest-only) recovery counts as interest; any part of it above the
     * interest written off is attributed Principal → Penalty → Insurance so the remaining split never exceeds the unrecovered total.
     *
     * @param  array{principal: float|null, penalty: float|null, interest: float|null, insurance: float|null}  $split
     * @param  iterable<LoanRecovery>  $standing
     * @return array{principal: float, penalty: float, interest: float, insurance: float}
     */
    private function recoveredByComponent(array $split, iterable $standing): array
    {
        $recovered = ['principal' => 0.0, 'penalty' => 0.0, 'interest' => 0.0, 'insurance' => 0.0];
        $legacy = 0.0;
        foreach ($standing as $recovery) {
            if ($recovery->isLegacy()) {
                $legacy += (float) $recovery->amount;

                continue;
            }
            foreach (LoanRecovery::COMPONENTS as $component) {
                $recovered[$component] += (float) $recovery->{$component.'_amount'};
            }
        }

        foreach (['interest', 'principal', 'penalty', 'insurance'] as $component) {
            if ($legacy <= 0.004) {
                break;
            }
            $room = max(0.0, (float) $split[$component] - $recovered[$component]);
            $portion = $component === 'insurance' ? $legacy : min($legacy, $room);
            $recovered[$component] += $portion;
            $legacy -= $portion;
        }

        return array_map(fn (float $value): float => round($value, 2), $recovered);
    }
}
