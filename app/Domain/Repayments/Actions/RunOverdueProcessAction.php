<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Actions;

use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Services\LoanStateMachine;
use App\Domain\Loans\Services\PenaltyCalculator;
use App\Domain\Repayments\Enums\TriggeredBy;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\PenaltyRun;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * §7's overdue/penalty job — `POST /loans/overdue/process` (§15.3).
 *
 * Walks every schedule past its due date that is not yet paid, applies the
 * product's penalty configuration through PenaltyCalculator (the same one
 * written in Phase 5), marks the installment overdue and moves the loan into
 * arrears.
 *
 * ## OSC-1 — why nothing is posted to the ledger here
 *
 * §7 says this job should also post "Dr Loan Arrears / Cr Expected Schedule".
 * That instruction cannot be followed as written, for two reasons:
 *
 *   1. "Expected Schedule" is not one of the accounts defined in §5. There is
 *      no such row in the chart, and inventing one would be inventing an
 *      accounting policy.
 *   2. §5 already recognises penalty income when a penalty is COLLECTED
 *      (Cr Penalty Income on repayment). Posting again on accrual would
 *      double-count penalty income — every penalty would appear twice in the
 *      P&L, once when charged and once when paid.
 *
 * So this job deliberately posts NOTHING. The accrued penalty lives on
 * `loan_schedules.penalty_due` and in `penalty_runs`, and reaches the ledger
 * exactly once, on collection. That is the same resolution the frontend
 * reached and documented.
 *
 * Resolving OSC-1 means choosing one of: (a) accept collection-basis
 * recognition, which is what is implemented; or (b) move to accrual basis,
 * which needs a real contra account added to §5 and the collection posting
 * changed to clear the receivable instead of recognising income. Both are
 * defensible; the specification currently implies both at once.
 *
 * @see README.md → "Open Specification Conflicts"
 */
final class RunOverdueProcessAction
{
    public function __construct(
        private readonly PenaltyCalculator $penalties,
        private readonly LoanStateMachine $states,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(TriggeredBy $triggeredBy, ?User $actor = null): PenaltyRun
    {
        $asOf = Date::now()->toImmutable();

        return DB::transaction(function () use ($asOf, $triggeredBy, $actor): PenaltyRun {
            $loansProcessed = 0;
            $installmentsPenalised = 0;
            $totalPenalty = Money::zero();

            $loans = Loan::query()
                ->with(['schedules', 'product'])
                ->whereIn('status', [LoanStatus::Active->value, LoanStatus::Arrears->value])
                ->get();

            foreach ($loans as $loan) {
                $touched = false;

                foreach ($loan->schedules as $schedule) {
                    if ($schedule->status === LoanScheduleStatus::Paid) {
                        continue;
                    }

                    if (! $schedule->outstandingTotal()->isPositive()) {
                        continue;
                    }

                    if ($schedule->due_date->startOfDay()->greaterThanOrEqualTo($asOf->startOfDay())) {
                        continue;
                    }

                    $penalty = $this->penalties->compute($schedule, $loan->product, $asOf);

                    $attributes = ['status' => LoanScheduleStatus::Overdue];

                    /*
                     * The penalty is TOPPED UP, not added: PenaltyCalculator
                     * returns the total owed as of today, so only the shortfall
                     * is charged and a figure already recorded is never
                     * reduced. This mirrors the frontend's guard exactly
                     * ("Only top up to the computed figure — re-running the job
                     * must not stack penalties on the same installment").
                     *
                     * OSC-4: that intent is not fully achieved by either
                     * implementation, and the divergence is in the
                     * specification rather than in this code. The penalty base
                     * is the installment's OUTSTANDING TOTAL, which includes
                     * the penalty already accrued — so a second run on the same
                     * day computes a slightly larger figure and tops up again.
                     * Charging a penalty on an unpaid penalty is a real policy
                     * choice, and §7 does not say which base it means.
                     *
                     * @see README.md → "Open Specification Conflicts"
                     */
                    if ($penalty->greaterThan($schedule->penaltyDue())) {
                        $attributes['penalty_due'] = $penalty->toDecimalString();
                        $totalPenalty = $totalPenalty->add($penalty->subtract($schedule->penaltyDue()));
                        $installmentsPenalised++;
                    }

                    $schedule->update($attributes);
                    $touched = true;
                }

                if ($touched) {
                    $loansProcessed++;

                    if ($loan->status === LoanStatus::Active) {
                        $this->states->transition($loan, LoanStatus::Arrears, $actor, 'Installment overdue');
                    }
                }
            }

            $run = PenaltyRun::query()->create([
                'run_date' => $asOf->toDateString(),
                'loans_processed' => $loansProcessed,
                'installments_penalised' => $installmentsPenalised,
                'total_penalty_applied' => $totalPenalty->toDecimalString(),
                'triggered_by' => $triggeredBy,
                'triggered_by_user_id' => $actor?->getKey(),
                'created_at' => Date::now(),
            ]);

            $this->audit->log(
                AuditAction::PenaltyRunExecuted,
                $run,
                after: [
                    'loans_processed' => $loansProcessed,
                    'installments_penalised' => $installmentsPenalised,
                    'total_penalty_applied' => $totalPenalty->toDecimalString(),
                    // Recorded on every run so the absence of a posting is a
                    // deliberate, visible decision rather than an omission.
                    'ledger_posting' => 'none (OSC-1: penalty income is recognised on collection)',
                ],
                actor: $actor,
            );

            return $run;
        });
    }
}
