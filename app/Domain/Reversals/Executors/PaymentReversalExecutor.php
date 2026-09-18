<?php

declare(strict_types=1);

namespace App\Domain\Reversals\Executors;

use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Domain\Repayments\Enums\PaymentStatus;
use App\Domain\Reversals\Enums\ReversalType;
use App\Domain\Reversals\Exceptions\ReversalRefusedException;
use App\Domain\Reversals\Services\LoanStandingRecalculator;
use App\Enums\AuditAction;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanAdvance;
use App\Models\LoanSchedule;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Undoes a receipt.
 *
 * RecordRepaymentAction does five things to a loan when money arrives, and a
 * reversal has to undo all five or it has undone none of them:
 *
 *   1. the mirrored journal entry            (the books)
 *   2. compensating allocation rows          (what this payment settled)
 *   3. the installments' paid columns        (the borrower's schedule)
 *   4. the advance movements                 (credit consumed or created)
 *   5. the loan's standing                   (closed / arrears / active)
 *
 * ## Why compensating rows instead of deletes
 *
 * §2 makes a payment undeletable on purpose, and its allocations are the
 * evidence for what it did. Deleting them would leave a reversed payment that
 * looks like it was never allocated at all, which is precisely the state an
 * auditor cannot tell apart from a bug. So the reversal writes NEGATIVE
 * allocation rows and negative advance movements: the sums come back to where
 * they were, and the history says why.
 */
final class PaymentReversalExecutor implements ReversalExecutor
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly LoanStandingRecalculator $standing,
        private readonly AuditLogger $audit,
    ) {}

    public function type(): ReversalType
    {
        return ReversalType::Payment;
    }

    public function guard(ReversalRequest $request): void
    {
        $payment = $this->payment($request);

        if ($payment->status === PaymentStatus::Reversed) {
            throw ReversalRefusedException::paymentAlreadyReversed($payment->payment_reference);
        }

        /*
         * Only money that has actually landed on a loan is this module's to
         * take back. An unmatched receipt is still sitting in Suspense and is
         * resolved on the suspense screen; one merely `received` has not been
         * allocated yet and can simply be re-pointed.
         */
        if (! in_array($payment->status, [
            PaymentStatus::Allocated,
            PaymentStatus::Confirmed,
            PaymentStatus::PendingVerification,
        ], true)) {
            throw ReversalRefusedException::paymentNotReversible(
                $payment->payment_reference,
                $payment->status->value,
            );
        }

        $loan = $payment->loan;

        if ($loan !== null
            && $loan->early_settled_at !== null
            && (int) $loan->early_settlement_payment_id === (int) $payment->getKey()) {
            throw ReversalRefusedException::paymentSettledLoanEarly($payment->payment_reference, $loan->loan_number);
        }
    }

    public function amount(ReversalRequest $request): Money
    {
        return $this->payment($request)->amountMoney();
    }

    public function describe(ReversalRequest $request): string
    {
        $payment = $this->payment($request);

        return sprintf(
            'Payment %s (%s)',
            $payment->payment_reference,
            $payment->loan?->loan_number ?? 'unmatched',
        );
    }

    public function execute(ReversalRequest $request, User $approver): ?JournalEntry
    {
        $payment = $this->payment($request);
        $loan = $payment->loan;

        $entry = $payment->journalEntry === null
            ? null
            : $this->ledger->reverse($payment->journalEntry, $request->reason, $approver);

        $this->unwindAllocations($payment);
        $this->unwindAdvances($payment, $entry, $approver);

        $payment->update(['status' => PaymentStatus::Reversed]);

        if ($loan !== null) {
            $this->standing->recalculate($loan->fresh(['schedules']), $approver, sprintf(
                'Reversal of payment %s',
                $payment->payment_reference,
            ));
        }

        $this->audit->log(
            AuditAction::PaymentReversed,
            $payment,
            after: [
                'reversal_request' => $request->getKey(),
                'reason' => $request->reason,
                'amount' => $payment->amount,
                'journal_entry' => $entry?->entry_number,
                'loan_number' => $loan?->loan_number,
            ],
            actor: $approver,
        );

        Log::channel('operations')->warning('Payment reversed', [
            'payment_reference' => $payment->payment_reference,
            'loan_number' => $loan?->loan_number,
            'amount' => $payment->amount,
            'approved_by' => $approver->getKey(),
            'journal_entry' => $entry?->entry_number,
        ]);

        return $entry;
    }

    /**
     * Takes the payment back off every installment it touched.
     *
     * The schedule is decremented by exactly what this payment put on it —
     * never floored at zero, because a negative paid column would mean the
     * allocations and the schedule disagree, and that is a bug worth seeing
     * rather than hiding. Money::subtract is exact in minor units, so the
     * arithmetic that added it and the arithmetic that removes it cancel.
     */
    private function unwindAllocations(Payment $payment): void
    {
        $allocations = $payment->allocations()->get();

        foreach ($allocations as $allocation) {
            // Skip the compensating rows a previous partial run may have left.
            if ($this->isCompensating($allocation)) {
                continue;
            }

            $schedule = LoanSchedule::query()->lockForUpdate()->find($allocation->loan_schedule_id);

            if ($schedule === null) {
                continue;
            }

            $penaltyPaid = Money::of($schedule->penalty_paid)->subtract(Money::of($allocation->penalty_allocated));
            $interestPaid = Money::of($schedule->interest_paid)->subtract(Money::of($allocation->interest_allocated));
            $principalPaid = Money::of($schedule->principal_paid)->subtract(Money::of($allocation->principal_allocated));

            $schedule->update([
                'penalty_paid' => $penaltyPaid->toDecimalString(),
                'interest_paid' => $interestPaid->toDecimalString(),
                'principal_paid' => $principalPaid->toDecimalString(),
                'status' => $this->statusFor($schedule, $penaltyPaid, $interestPaid, $principalPaid)->value,
            ]);

            PaymentAllocation::query()->create([
                'payment_id' => $payment->getKey(),
                'loan_schedule_id' => $schedule->getKey(),
                'penalty_allocated' => Money::of($allocation->penalty_allocated)->multiply(-1)->toDecimalString(),
                'interest_allocated' => Money::of($allocation->interest_allocated)->multiply(-1)->toDecimalString(),
                'principal_allocated' => Money::of($allocation->principal_allocated)->multiply(-1)->toDecimalString(),
                'created_at' => Date::now(),
            ]);
        }
    }

    /**
     * Reverses the advance movements this payment caused.
     *
     * One compensating row for the net effect, `kind = refund`, carrying the
     * balance it leaves behind — so the loan's advance statement reads forward
     * through the reversal instead of jumping.
     */
    private function unwindAdvances(Payment $payment, ?JournalEntry $entry, User $approver): void
    {
        $movements = LoanAdvance::query()
            ->where('payment_id', $payment->getKey())
            ->whereIn('kind', [LoanAdvance::KIND_CREDIT, LoanAdvance::KIND_CONSUMPTION])
            ->get();

        if ($movements->isEmpty()) {
            return;
        }

        $net = Money::sum($movements->map(fn (LoanAdvance $m): Money => $m->amountMoney()));

        if ($net->isZero()) {
            return;
        }

        $balance = LoanAdvance::balanceFor((int) $payment->loan_id)->subtract($net);

        LoanAdvance::query()->create([
            'loan_id' => $payment->loan_id,
            'payment_id' => $payment->getKey(),
            'amount' => $net->multiply(-1)->toDecimalString(),
            'balance_after' => $balance->toDecimalString(),
            'kind' => LoanAdvance::KIND_REFUND,
            'narrative' => sprintf('Reversal of %s', $payment->payment_reference),
            'journal_entry_id' => $entry?->getKey(),
            'created_by' => $approver->getKey(),
        ]);
    }

    /**
     * An installment's status after money came off it.
     *
     * `cancelled` is left alone: it was voided by an early settlement, and a
     * reversal of one payment does not un-settle the loan.
     */
    private function statusFor(
        LoanSchedule $schedule,
        Money $penaltyPaid,
        Money $interestPaid,
        Money $principalPaid,
    ): LoanScheduleStatus {
        if ($schedule->status === LoanScheduleStatus::Cancelled) {
            return LoanScheduleStatus::Cancelled;
        }

        $fullyPaid = ! $penaltyPaid->lessThan($schedule->penaltyDue())
            && ! $interestPaid->lessThan($schedule->interestDue())
            && ! $principalPaid->lessThan($schedule->principalDue());

        if ($fullyPaid) {
            return LoanScheduleStatus::Paid;
        }

        $overdue = $schedule->due_date->isBefore(Date::now()->startOfDay());

        if ($penaltyPaid->isPositive() || $interestPaid->isPositive() || $principalPaid->isPositive()) {
            return $overdue ? LoanScheduleStatus::Overdue : LoanScheduleStatus::Partial;
        }

        return $overdue ? LoanScheduleStatus::Overdue : LoanScheduleStatus::Pending;
    }

    private function isCompensating(PaymentAllocation $allocation): bool
    {
        return Money::of($allocation->penalty_allocated)->isNegative()
            || Money::of($allocation->interest_allocated)->isNegative()
            || Money::of($allocation->principal_allocated)->isNegative();
    }

    private function payment(ReversalRequest $request): Payment
    {
        $payment = $request->payment;

        if ($payment === null) {
            throw ReversalRefusedException::subjectMissing('payment');
        }

        return $payment->loadMissing(['loan.schedules', 'journalEntry']);
    }
}
