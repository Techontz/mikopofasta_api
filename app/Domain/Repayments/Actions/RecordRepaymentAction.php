<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Actions;

use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Repayments\DTOs\AllocationResult;
use App\Domain\Repayments\Enums\PaymentChannel;
use App\Domain\Repayments\Enums\PaymentStatus;
use App\Domain\Repayments\Enums\SuspenseStatus;
use App\Domain\Repayments\Services\LoanStatusReconciler;
use App\Domain\Repayments\Services\PaymentAllocator;
use App\Domain\Repayments\Services\PaymentReferenceGenerator;
use App\Domain\Repayments\Services\RepaymentPostingBuilder;
use App\Enums\AuditAction;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanAdvance;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The shared repayment core — §7's "three intake channels, one allocation
 * core".
 *
 * Every payment that reaches a loan comes through `applyToLoan()`: the
 * provider webhook, the teller's cash entry, and a Finance officer resolving a
 * suspense item. They differ only in which account is debited and what the
 * entry is called; the allocation, the schedule updates, the ledger posting
 * and the loan-status consequences are identical, and are written once here.
 *
 * Everything happens in ONE transaction. A payment whose allocation committed
 * but whose ledger entry did not would be money the customer has been credited
 * for and the books have never seen.
 */
final class RecordRepaymentAction
{
    public function __construct(
        private readonly PaymentAllocator $allocator,
        private readonly RepaymentPostingBuilder $postings,
        private readonly LedgerService $ledger,
        private readonly AccountResolver $accounts,
        private readonly LoanStatusReconciler $reconciler,
        private readonly PaymentReferenceGenerator $references,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Allocates a payment against a loan and posts the result.
     *
     * @param bool $viaSuspense true when the money already sits in Suspense,
     *                          so Suspense is drawn down instead of debiting
     *                          cash a second time (§5).
     * @param CarbonImmutable|null $asOf the date to settle installments up to.
     *                                   Defaults to now, which is what every ordinary payment means. Early
     *                                   settlement passes the loan's FINAL due date, which lifts the
     *                                   due-date gate so cash reaches the whole schedule — the one
     *                                   circumstance in which that is correct, because the borrower has
     *                                   deliberately asked to close the loan rather than to pay ahead.
     */
    public function applyToLoan(
        Payment $payment,
        Loan $loan,
        bool $viaSuspense,
        User $actor,
        ?CarbonImmutable $asOf = null,
    ): AllocationResult {
        return DB::transaction(function () use ($payment, $loan, $viaSuspense, $actor, $asOf): AllocationResult {
            $loan->loadMissing(['schedules', 'branch']);

            /*
             * Step 2 of the confirmed order is Advance, so whatever the
             * borrower has already paid ahead is spent before this payment's
             * cash touches principal or interest.
             */
            $advanceBefore = LoanAdvance::balanceFor((int) $loan->getKey());

            /*
             * Only installments that have reached their due date are settled.
             * Anything this payment cannot place falls out as `unallocated` and
             * is banked below as an advance credit — the client's confirmed
             * prepaid-credit model.
             */
            $allocation = $this->allocator->allocate(
                $payment->amountMoney(),
                $loan->schedules,
                $advanceBefore,
                $asOf ?? Date::now()->toImmutable(),
            );

            foreach ($allocation->lines as $line) {
                $schedule = $loan->schedules->firstWhere('id', $line->scheduleId);

                if ($schedule === null) {
                    continue;
                }

                $schedule->update($this->allocator->applyToSchedule($schedule, $line));

                $payment->allocations()->create($line->toDatabaseRow((int) $payment->getKey()) + [
                    'created_at' => Date::now(),
                ]);
            }

            $entry = null;

            if ($allocation->allocatedTotal()->isPositive() || $allocation->unallocated->isPositive()) {
                $lines = $viaSuspense
                    ? $this->postings->buildSuspenseResolution($loan, $allocation)
                    : $this->postings->build(
                        $loan,
                        $allocation,
                        $this->accounts->cashAccountFor($payment->channel->isCash(), $loan->branch),
                    );

                $entry = $this->ledger->post(
                    description: sprintf('Repayment %s for %s', $payment->payment_reference, $loan->loan_number),
                    sourceType: $viaSuspense ? JournalSourceType::SuspenseResolution : JournalSourceType::Repayment,
                    sourceId: (int) $payment->getKey(),
                    lines: $lines,
                    postedBy: $actor,
                );
            }

            /*
             * Record the advance movements this payment caused, so the credit
             * has a statement rather than only a balance. Both are written
             * inside the same transaction as the allocation and the posting —
             * an advance that survived a rolled-back payment would be money the
             * borrower never paid.
             */
            $this->recordAdvanceMovements($loan, $payment, $allocation, $advanceBefore, $entry, $actor);

            $payment->update([
                'loan_id' => $loan->getKey(),
                'customer_id' => $loan->customer_id,
                'branch_id' => $payment->branch_id ?? $loan->branch_id,
                'status' => $this->statusAfterAllocation($payment),
                'journal_entry_id' => $entry?->getKey(),
            ]);

            $this->reconciler->reconcile($loan->fresh(['schedules']), $actor);

            $this->audit->log(
                AuditAction::PaymentAllocated,
                $payment,
                after: [
                    'loan_id' => $loan->getKey(),
                    'allocated' => $allocation->allocatedTotal()->toDecimalString(),
                    'unallocated' => $allocation->unallocated->toDecimalString(),
                    'advance_consumed' => $allocation->advanceConsumed->toDecimalString(),
                    'penalty' => $allocation->totalPenalty()->toDecimalString(),
                    'interest' => $allocation->totalInterest()->toDecimalString(),
                    'principal' => $allocation->totalPrincipal()->toDecimalString(),
                    'journal_entry' => $entry?->entry_number,
                ],
                actor: $actor,
            );

            Log::channel('operations')->info('Repayment processed', [
                'payment_reference' => $payment->payment_reference,
                'loan_number' => $loan->loan_number,
                'channel' => $payment->channel->value,
                'amount' => $payment->amount,
                'allocated' => $allocation->allocatedTotal()->toDecimalString(),
                'unallocated' => $allocation->unallocated->toDecimalString(),
                'journal_entry' => $entry?->entry_number,
                'via_suspense' => $viaSuspense,
            ]);

            return $allocation;
        });
    }

    /**
     * Records money that could not be matched to a loan.
     *
     * §7: a miss "creates the payment with status=unmatched and an
     * accompanying suspense_items row — it is never dropped". §5 adds that it
     * is still ledgered the moment it arrives: Dr Cash/Bank · Cr Suspense.
     * Nothing sits un-ledgered.
     */
    public function recordUnmatched(
        Money $amount,
        PaymentChannel $channel,
        ?string $transactionId,
        string $reason,
        User $actor,
        ?int $branchId = null,
    ): Payment {
        return DB::transaction(function () use ($amount, $channel, $transactionId, $reason, $actor, $branchId): Payment {
            $payment = Payment::query()->create([
                'payment_reference' => $this->references->next(),
                'amount' => $amount->toDecimalString(),
                'channel' => $channel,
                'transaction_id' => $transactionId,
                'status' => PaymentStatus::Unmatched,
                'branch_id' => $branchId,
                'received_at' => Date::now(),
                'created_by' => $actor->getKey(),
            ]);

            $branch = $branchId === null ? null : \App\Models\Branch::query()->find($branchId);

            $entry = $this->ledger->post(
                description: sprintf('Unmatched receipt %s', $payment->payment_reference),
                sourceType: JournalSourceType::Repayment,
                sourceId: (int) $payment->getKey(),
                lines: $this->postings->buildUnmatched(
                    $amount,
                    $this->accounts->cashAccountFor($channel->isCash(), $branch),
                    $branchId,
                ),
                postedBy: $actor,
            );

            $payment->update(['journal_entry_id' => $entry->getKey()]);

            $payment->suspenseItem()->create([
                'reason' => $reason,
                'amount' => $amount->toDecimalString(),
                'status' => SuspenseStatus::Unallocated,
            ]);

            $this->audit->log(
                AuditAction::PaymentUnmatched,
                $payment,
                after: ['reason' => $reason, 'journal_entry' => $entry->entry_number],
                actor: $actor,
            );

            return $payment->fresh(['suspenseItem']);
        });
    }

    /**
     * Cash is only `allocated` once a deposit has been reconciled against it
     * (§7's two trust states); every other channel is settled on arrival.
     */
    /**
     * Writes the advance movements one payment caused.
     *
     * Up to two rows, in the order they happened: the consumption of an
     * existing credit, then the creation of a new one from any surplus. Both
     * carry the running balance so the loan's advance statement reads as a
     * statement rather than as a list of deltas.
     *
     * Only the CREDIT references the journal entry. A consumption moved no cash
     * — the money was recognised when it arrived — so pointing it at the entry
     * would suggest a posting that does not describe it.
     */
    private function recordAdvanceMovements(
        Loan $loan,
        Payment $payment,
        AllocationResult $allocation,
        Money $balanceBefore,
        ?JournalEntry $entry,
        User $actor,
    ): void {
        $balance = $balanceBefore;

        if ($allocation->advanceConsumed->isPositive()) {
            $balance = $balance->subtract($allocation->advanceConsumed);

            LoanAdvance::query()->create([
                'loan_id' => $loan->getKey(),
                'payment_id' => $payment->getKey(),
                'amount' => $allocation->advanceConsumed->multiply(-1)->toDecimalString(),
                'balance_after' => $balance->toDecimalString(),
                'kind' => LoanAdvance::KIND_CONSUMPTION,
                'narrative' => sprintf('Consumed against %s', $payment->payment_reference),
                'created_by' => $actor->getKey(),
            ]);
        }

        if ($allocation->unallocated->isPositive()) {
            $balance = $balance->add($allocation->unallocated);

            LoanAdvance::query()->create([
                'loan_id' => $loan->getKey(),
                'payment_id' => $payment->getKey(),
                'amount' => $allocation->unallocated->toDecimalString(),
                'balance_after' => $balance->toDecimalString(),
                'kind' => LoanAdvance::KIND_CREDIT,
                'narrative' => sprintf('Paid ahead on %s', $payment->payment_reference),
                'journal_entry_id' => $entry?->getKey(),
                'created_by' => $actor->getKey(),
            ]);
        }
    }

    private function statusAfterAllocation(Payment $payment): PaymentStatus
    {
        return $payment->channel->isCash()
            ? PaymentStatus::PendingVerification
            : PaymentStatus::Allocated;
    }
}
