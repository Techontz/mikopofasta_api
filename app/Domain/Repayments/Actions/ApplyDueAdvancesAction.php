<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Actions;

use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Ledger\Services\SystemActor;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Repayments\DTOs\AdvanceConsumptionRun;
use App\Domain\Repayments\Services\LoanStatusReconciler;
use App\Domain\Repayments\Services\PaymentAllocator;
use App\Domain\Repayments\Services\RepaymentPostingBuilder;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\LoanAdvance;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The second half of the client's advance ruling, and the half that makes it a
 * prepaid credit rather than a parking account.
 *
 *     … the repayment schedule remains unchanged
 *       → when each installment reaches its due date
 *       → the system automatically consumes the Advance first
 *       → only the remaining amount, if any, is requested from the customer
 *
 * PaymentAllocator settles what is due at the moment money arrives. Nothing
 * arrives on the day an installment matures, so without this pass a borrower
 * who paid three months up front would still fall into arrears on schedule and
 * be penalised on money the lender is already holding. This is the job that
 * closes that gap.
 *
 * ## Why it runs before the penalty job, not after
 *
 * RunOverdueProcessAction calls this first. The order is not cosmetic: an
 * installment that an advance can cover was never late, and charging a penalty
 * before spending the credit would create a charge that the very next step
 * proves was never owed. Reversing it afterwards would leave a penalty and its
 * reversal in the borrower's statement for something that never happened.
 *
 * ## What it deliberately does not do
 *
 * It never touches an installment that has not fallen due — that is the whole
 * point of the ruling — and it never spends an advance on a penalty. Penalties
 * are settled from cash, for the reason set out in PaymentAllocator: money paid
 * early should not be consumed by a charge for being late.
 */
final class ApplyDueAdvancesAction
{
    public function __construct(
        private readonly PaymentAllocator $allocator,
        private readonly RepaymentPostingBuilder $postings,
        private readonly LedgerService $ledger,
        private readonly LoanStatusReconciler $reconciler,
        private readonly SystemActor $system,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Consumes held advances against every installment that has fallen due.
     *
     * @param CarbonImmutable|null $asOf the date to treat as "today"; explicit
     *                                   so a run can be reproduced and audited
     */
    public function handle(?User $actor = null, ?CarbonImmutable $asOf = null): AdvanceConsumptionRun
    {
        $today = ($asOf ?? Date::now()->toImmutable())->startOfDay();

        /*
         * Resolved ONCE, at the top, and used for everything this run touches:
         * the ledger entry, the advance movement's `created_by`, the status
         * history and the audit row.
         *
         * Previously only the ledger posting named the System account and the
         * rest recorded null. That left the same action attributed two
         * different ways in three different tables — and "who consumed this
         * borrower's advance" answerable from one of them and not the others.
         *
         * If the platform is not initialised this throws here, before a single
         * row is written, rather than part-way through the loop.
         */
        $actor ??= $this->system->resolve();

        return DB::transaction(function () use ($today, $actor): AdvanceConsumptionRun {
            $loansSettled = 0;
            $installmentsSettled = 0;
            $consumed = Money::zero();

            foreach ($this->loansHoldingAdvance() as $loan) {
                $applied = $this->applyTo($loan, $today, $actor);

                if ($applied === null) {
                    continue;
                }

                $loansSettled++;
                $installmentsSettled += $applied['installments'];
                $consumed = $consumed->add($applied['consumed']);
            }

            $run = new AdvanceConsumptionRun(
                runDate: $today,
                loansSettled: $loansSettled,
                installmentsSettled: $installmentsSettled,
                advanceConsumed: $consumed,
            );

            Log::channel('operations')->info('Due advances applied', $run->toArray());

            return $run;
        });
    }

    /**
     * Loans that hold a credit and could still use it.
     *
     * One aggregate query rather than a balance lookup per loan: a portfolio of
     * 100,000 loans cannot afford a daily job that issues 100,000 queries to
     * discover that almost none of them hold an advance.
     *
     * @return \Illuminate\Support\Collection<int, Loan>
     */
    private function loansHoldingAdvance(): \Illuminate\Support\Collection
    {
        $loanIds = DB::table('loan_advances')
            ->select('loan_id')
            ->groupBy('loan_id')
            ->havingRaw('SUM(amount) > 0')
            ->pluck('loan_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($loanIds === []) {
            return collect();
        }

        return Loan::query()
            ->with(['schedules', 'branch'])
            ->whereIn('id', $loanIds)
            ->whereIn('status', [LoanStatus::Active->value, LoanStatus::Arrears->value])
            ->get();
    }

    /**
     * Spends one loan's advance on whatever it now owes.
     *
     * @return array{installments: int, consumed: Money}|null null when the
     *                                                        credit could not be placed — nothing is due yet, or what is due
     *                                                        is only penalty, which an advance does not pay
     */
    private function applyTo(Loan $loan, CarbonImmutable $today, User $actor): ?array
    {
        $balance = LoanAdvance::balanceFor((int) $loan->getKey());

        if (! $balance->isPositive()) {
            return null;
        }

        // No cash: this pass spends credit only.
        $allocation = $this->allocator->allocate(Money::zero(), $loan->schedules, $balance, $today);

        if (! $allocation->advanceConsumed->isPositive()) {
            return null;
        }

        foreach ($allocation->lines as $line) {
            $schedule = $loan->schedules->firstWhere('id', $line->scheduleId);

            if ($schedule === null) {
                continue;
            }

            $schedule->update($this->allocator->applyToSchedule($schedule, $line));
        }

        $entry = $this->ledger->post(
            description: sprintf('Advance applied to %s', $loan->loan_number),
            sourceType: JournalSourceType::AdvanceConsumption,
            sourceId: (int) $loan->getKey(),
            lines: $this->postings->buildAdvanceConsumption($loan, $allocation),
            // Always an identity, never a guess: the System account when the
            // scheduler ran, the operator when a person triggered it.
            postedBy: $actor,
        );

        $remaining = $balance->subtract($allocation->advanceConsumed);

        LoanAdvance::query()->create([
            'loan_id' => $loan->getKey(),
            'amount' => $allocation->advanceConsumed->multiply(-1)->toDecimalString(),
            'balance_after' => $remaining->toDecimalString(),
            'kind' => LoanAdvance::KIND_CONSUMPTION,
            'narrative' => sprintf('Applied to installments due %s', $today->toDateString()),
            /*
             * Unlike a consumption inside a repayment, this one DOES carry the
             * entry: it is the posting that describes it. Nothing else moved
             * money on this loan today.
             */
            'journal_entry_id' => $entry->getKey(),
            'created_by' => $actor->getKey(),
        ]);

        $this->reconciler->reconcile($loan->fresh(['schedules']), $actor);

        $this->audit->log(
            AuditAction::LoanAdvanceConsumed,
            $loan,
            before: ['advance_balance' => $balance->toDecimalString()],
            after: [
                'advance_balance' => $remaining->toDecimalString(),
                'consumed' => $allocation->advanceConsumed->toDecimalString(),
                'installments' => count($allocation->lines),
                'principal' => $allocation->totalPrincipal()->toDecimalString(),
                'interest' => $allocation->totalInterest()->toDecimalString(),
                'journal_entry' => $entry->entry_number,
                'as_of' => $today->toDateString(),
            ],
            actor: $actor,
        );

        return ['installments' => count($allocation->lines), 'consumed' => $allocation->advanceConsumed];
    }
}
