<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Actions;

use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Services\LoanStateMachine;
use App\Domain\Repayments\DTOs\AllocationResult;
use App\Domain\Repayments\Enums\PaymentChannel;
use App\Domain\Repayments\Enums\PaymentStatus;
use App\Domain\Repayments\Enums\SuspenseStatus;
use App\Domain\Repayments\Services\PaymentAllocator;
use App\Domain\Repayments\Services\PaymentReferenceGenerator;
use App\Domain\Repayments\Services\RepaymentPostingBuilder;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
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
        private readonly LoanStateMachine $states,
        private readonly PaymentReferenceGenerator $references,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Allocates a payment against a loan and posts the result.
     *
     * @param bool $viaSuspense true when the money already sits in Suspense,
     *                          so Suspense is drawn down instead of debiting
     *                          cash a second time (§5).
     */
    public function applyToLoan(
        Payment $payment,
        Loan $loan,
        bool $viaSuspense,
        User $actor,
    ): AllocationResult {
        return DB::transaction(function () use ($payment, $loan, $viaSuspense, $actor): AllocationResult {
            $loan->loadMissing(['schedules', 'branch']);

            $allocation = $this->allocator->allocate($payment->amountMoney(), $loan->schedules);

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

            if ($allocation->allocatedTotal()->isPositive()) {
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

            $payment->update([
                'loan_id' => $loan->getKey(),
                'customer_id' => $loan->customer_id,
                'branch_id' => $payment->branch_id ?? $loan->branch_id,
                'status' => $this->statusAfterAllocation($payment),
                'journal_entry_id' => $entry?->getKey(),
            ]);

            $this->reconcileLoanStatus($loan->fresh(['schedules']), $actor);

            $this->audit->log(
                AuditAction::PaymentAllocated,
                $payment,
                after: [
                    'loan_id' => $loan->getKey(),
                    'allocated' => $allocation->allocatedTotal()->toDecimalString(),
                    'unallocated' => $allocation->unallocated->toDecimalString(),
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
    private function statusAfterAllocation(Payment $payment): PaymentStatus
    {
        return $payment->channel->isCash()
            ? PaymentStatus::PendingVerification
            : PaymentStatus::Allocated;
    }

    /**
     * Moves the loan on if the repayment changed its standing.
     *
     * Two §10 transitions live here: arrears → active when the last overdue
     * installment is cleared, and → closed when nothing at all is outstanding.
     * Closing is what makes "early settlement" a real outcome rather than a
     * loan that merely happens to have a zero balance.
     */
    private function reconcileLoanStatus(Loan $loan, User $actor): void
    {
        $stillOverdue = $loan->schedules->contains(
            fn ($s): bool => $s->status->value === 'overdue' && $s->outstandingTotal()->isPositive(),
        );

        if ($loan->status === LoanStatus::Arrears && ! $stillOverdue) {
            $this->states->transition($loan, LoanStatus::Active, $actor, 'Arrears cleared by repayment');
        }

        if (! $loan->outstandingTotal()->isPositive() && $loan->status->isOpenBook()) {
            if ($loan->status === LoanStatus::Arrears) {
                $this->states->transition($loan, LoanStatus::Active, $actor, 'Arrears cleared by final repayment');
            }

            $this->states->transition($loan, LoanStatus::Closed, $actor, 'Loan fully repaid');

            $loan->update([
                'closed_at' => Date::now(),
                'frozen_until' => Date::now()->addDays(\App\Domain\Loans\Actions\CloseLoanAction::DEFAULT_FREEZE_DAYS)->toDateString(),
            ]);

            $this->audit->log(
                AuditAction::LoanClosedByRepayment,
                $loan,
                after: ['closed_at' => Date::now()->toIso8601String()],
                actor: $actor,
            );
        }
    }
}
