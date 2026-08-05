<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Domain\Loans\DTOs\EarlySettlementQuote;
use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Exceptions\EarlySettlementException;
use App\Domain\Loans\Services\EarlySettlementQuoter;
use App\Domain\Loans\Services\LoanStateMachine;
use App\Domain\Repayments\Actions\RecordRepaymentAction;
use App\Domain\Repayments\Enums\PaymentChannel;
use App\Domain\Repayments\Enums\PaymentStatus;
use App\Domain\Repayments\Services\PaymentReferenceGenerator;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * "Close Loan Early" — client Decision 1, Option B.
 *
 *     Officer intentionally chooses Close Loan Early
 *       → the system recalculates the final payable amount
 *       → the loan immediately becomes Completed
 *       → future installments are cancelled
 *       → everything is fully audited
 *
 * The deliberate counterpart to Option A. Paying the balance early does NOT do
 * this — that money is held as an advance and the schedule stands. Settlement
 * is an act somebody takes on purpose, which is why it has its own endpoint,
 * its own permission and its own audit action.
 *
 * ## "Completed" is the existing `closed` status
 *
 * A loan that has been paid off is already `closed`, and the whole system —
 * eligibility cooldown, top-up rules, portfolio reports, the frontend's
 * TERMINAL_STATUSES — is built on that. Adding a second terminal "paid off"
 * state would give the same fact two names and force every one of those places
 * to check both. `early_settled_at` is what distinguishes a settlement from a
 * loan that simply ran its course.
 *
 * ## Why the waived interest posts nothing
 *
 * Interest income is recognised on COLLECTION, not accrual (OSC-1). Interest
 * that will now never be collected was never recognised, so there is nothing to
 * reverse. Waiving it is a change to what is owed, not to what was earned — and
 * the trial balance does not move.
 */
final class SettleLoanEarlyAction
{
    public function __construct(
        private readonly EarlySettlementQuoter $quoter,
        private readonly RecordRepaymentAction $repayments,
        private readonly LoanStateMachine $states,
        private readonly PaymentReferenceGenerator $references,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Settles the loan and closes it.
     *
     * @param Money $tendered cash handed over now, on top of any advance held
     *
     * @throws EarlySettlementException
     */
    public function handle(Loan $loan, Money $tendered, User $actor, int $freezeDays = CloseLoanAction::DEFAULT_FREEZE_DAYS): Loan
    {
        $this->guard($loan);

        $asOf = Date::now()->toImmutable();
        $quote = $this->quoter->quote($loan, $asOf);

        /*
         * Re-quoted here rather than trusting a figure the client sent. An
         * officer's screen can be minutes old, and a penalty accruing in
         * between would otherwise close a loan for less than it owed.
         */
        if ($tendered->lessThan($quote->cashRequired())) {
            throw EarlySettlementException::shortfall($quote->cashRequired(), $tendered);
        }

        return DB::transaction(function () use ($loan, $tendered, $actor, $quote, $freezeDays, $asOf): Loan {
            /*
             * The waiver comes FIRST, and the order is not cosmetic.
             *
             * PaymentAllocator walks installment by installment — penalty,
             * principal, interest, then on to the next one. Collecting before
             * waiving would therefore spend the settlement cash on interest
             * belonging to early installments and run out before reaching the
             * principal of later ones, leaving a "settled" loan still owing
             * money and a waiver figure that did not match the quote.
             *
             * Forgiving the unearned interest first makes the loan's remaining
             * debt exactly the quoted payable, so any allocation order settles
             * it to the shilling.
             */
            $waived = $this->waiveUnearnedInterest($loan, $asOf);

            $payment = null;

            if ($tendered->isPositive() || $quote->advanceHeld->isPositive()) {
                $payment = $this->collect($loan->fresh(['schedules']), $tendered, $actor);
            }

            $this->cancelUnbilledInstallments($loan->fresh(['schedules']), $asOf);

            $loan->refresh();

            $this->close($loan, $actor, $waived, $freezeDays, $payment);

            $this->audit->log(
                AuditAction::LoanSettledEarly,
                $loan,
                before: ['status' => LoanStatus::Active->value],
                after: $quote->toArray() + [
                    'tendered' => $tendered->toDecimalString(),
                    'interest_actually_waived' => $waived->toDecimalString(),
                    'settled_at' => $asOf->toIso8601String(),
                    'ledger_posting' => 'the collection only — waived interest was never recognised (OSC-1)',
                ],
                actor: $actor,
            );

            return $loan->fresh(['customer', 'product', 'schedules', 'branch']);
        });
    }

    /** The quote an officer is shown before deciding. */
    public function quote(Loan $loan): EarlySettlementQuote
    {
        $this->guard($loan);

        return $this->quoter->quote($loan);
    }

    /**
     * Takes the money in, through the ordinary repayment core.
     *
     * `asOf` is the loan's LAST due date, which is what makes this different
     * from a normal payment: the allocator's due-date gate is lifted, so cash
     * reaches every installment instead of only the matured ones. Everything
     * else — the confirmed Penalty → Advance → Principal → Interest order, the
     * ledger posting, the advance movements, the audit row — is the same code
     * every other payment goes through.
     */
    private function collect(Loan $loan, Money $tendered, User $actor): Payment
    {
        $payment = Payment::query()->create([
            'payment_reference' => $this->references->next(),
            'loan_id' => $loan->getKey(),
            'customer_id' => $loan->customer_id,
            'amount' => $tendered->toDecimalString(),
            'channel' => PaymentChannel::Cash,
            'status' => PaymentStatus::Received,
            'branch_id' => $loan->branch_id,
            'teller_id' => $actor->getKey(),
            'received_at' => Date::now(),
            'created_by' => $actor->getKey(),
        ]);

        $finalDueDate = $loan->schedules->max('due_date');

        $this->repayments->applyToLoan(
            $payment,
            $loan,
            viaSuspense: false,
            actor: $actor,
            asOf: $finalDueDate?->copy()->toImmutable(),
        );

        return $payment;
    }

    /**
     * Forgives interest on installments that have not been billed yet.
     *
     * `interest_due` is reduced to what was actually paid, so both the PHP
     * balance and the SQL aggregate agree that nothing is outstanding — one of
     * them still reading a debt the borrower has been forgiven is how a settled
     * loan turns up in an arrears report.
     *
     * The original schedule is not lost: LoanEngine can regenerate it from the
     * loan's own snapshot terms, and the audit row records what was waived.
     *
     * Principal is untouched. The borrower is repaying the money, not being
     * given it.
     */
    private function waiveUnearnedInterest(Loan $loan, CarbonImmutable $asOf): Money
    {
        $loan->loadMissing('schedules');

        $waived = Money::zero();

        foreach ($loan->schedules as $schedule) {
            if ($schedule->status === LoanScheduleStatus::Cancelled) {
                continue;
            }

            if ($this->quoter->hasFallenDue($schedule, $asOf)) {
                continue;
            }

            $interest = $schedule->outstandingInterest();

            if (! $interest->isPositive()) {
                continue;
            }

            $waived = $waived->add($interest);

            $schedule->update([
                'interest_due' => Money::of($schedule->interest_paid)->toDecimalString(),
            ]);
        }

        return $waived;
    }

    /**
     * Marks the installments the borrower never reached as cancelled.
     *
     * After collection, so the allocator has already recomputed each row's
     * status from what was paid — doing it earlier would have the allocator
     * overwrite `cancelled` with `paid` and lose the distinction the client
     * asked for.
     *
     * Only rows that had not yet been billed. A matured installment that the
     * settlement cleared was genuinely paid, and calling it cancelled would
     * misreport a customer who met every obligation that fell due.
     */
    private function cancelUnbilledInstallments(Loan $loan, CarbonImmutable $asOf): void
    {
        $loan->loadMissing('schedules');

        foreach ($loan->schedules as $schedule) {
            if ($this->quoter->hasFallenDue($schedule, $asOf)) {
                continue;
            }

            $schedule->update(['status' => LoanScheduleStatus::Cancelled]);
        }
    }

    private function close(Loan $loan, User $actor, Money $waived, int $freezeDays, ?Payment $payment): void
    {
        /*
         * The collection may already have closed it.
         *
         * Once the waiver has removed the unearned interest, the settlement
         * payment clears the last shilling — and RecordRepaymentAction's own
         * reconciler closes a loan that owes nothing. That is the correct
         * behaviour and not worth suppressing; what matters is that this method
         * does not then try to move an already-closed loan, which the state
         * machine would rightly refuse.
         */
        if ($loan->status !== LoanStatus::Closed) {
            if ($loan->status === LoanStatus::Arrears) {
                $this->states->transition($loan, LoanStatus::Active, $actor, 'Arrears cleared by early settlement');
            }

            $this->states->transition($loan, LoanStatus::Closed, $actor, 'Loan settled early');
        }

        $loan->update([
            'closed_at' => Date::now(),
            'early_settled_at' => Date::now(),
            /*
             * Who decided, and what they took in.
             *
             * Recorded on the loan rather than left to the audit log, because
             * the settlement screen has to show an officer and a reference
             * alongside the waived figure — and a screen that reconstructs
             * those by searching the payments table is a screen that can
             * disagree with the record it is describing.
             *
             * The payment is null when the waiver alone settled the loan: a
             * balance made entirely of unearned interest needs no cash, and
             * `collect()` is never called for it. The officer is recorded
             * either way, which is why it is not read off the payment.
             */
            'early_settled_by' => $actor->getKey(),
            'early_settlement_payment_id' => $payment?->getKey(),
            'interest_waived' => $waived->toDecimalString(),
            'frozen_until' => $freezeDays > 0 ? Date::now()->addDays($freezeDays)->toDateString() : null,
        ]);
    }

    /**
     * @throws EarlySettlementException
     */
    private function guard(Loan $loan): void
    {
        if (! $loan->status->isOpenBook() || $loan->status === LoanStatus::Defaulted) {
            throw EarlySettlementException::notSettleable($loan->status);
        }

        $loan->loadMissing('schedules');

        if ($loan->schedules->isEmpty()) {
            throw EarlySettlementException::noSchedule();
        }

        $anythingLeft = $loan->schedules->contains(
            fn (LoanSchedule $s): bool => $s->status !== LoanScheduleStatus::Cancelled
                && $s->outstandingTotal()->isPositive(),
        );

        if (! $anythingLeft) {
            throw EarlySettlementException::nothingOutstanding();
        }
    }
}
