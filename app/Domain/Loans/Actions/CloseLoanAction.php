<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Exceptions\LoanStateException;
use App\Domain\Loans\Services\LoanStateMachine;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * `POST /loans/{loan}/close` (§15.2) and the cancellation path.
 *
 * §6: closing a loan sets `closed_at` and starts a freeze window that blocks
 * new applications until it lapses — enforced in the eligibility check, not
 * just the UI (LoanEligibilityChecker's CUSTOMER_IN_COOLDOWN gate reads
 * exactly this column).
 */
final class CloseLoanAction
{
    /**
     * The frontend's CloseLoanInputSchema default.
     */
    public const int DEFAULT_FREEZE_DAYS = 30;

    public function __construct(
        private readonly LoanStateMachine $states,
        private readonly AuditLogger $audit,
    ) {}

    public function close(Loan $loan, int $freezeDays, User $actor): Loan
    {
        return DB::transaction(function () use ($loan, $freezeDays, $actor): Loan {
            $this->states->transition($loan, LoanStatus::Closed, $actor, 'Loan closed');

            $loan->update([
                'closed_at' => Date::now(),
                'frozen_until' => $freezeDays > 0
                    ? Date::now()->addDays($freezeDays)->toDateString()
                    : null,
            ]);

            $this->audit->log(
                AuditAction::LoanCancelled,
                $loan,
                after: ['closed_at' => $loan->closed_at?->toIso8601String(), 'frozen_until' => $loan->frozen_until?->toDateString()],
                actor: $actor,
            );

            return $loan->fresh(['customer', 'product']);
        });
    }

    /**
     * Withdraws an application before it has been approved.
     *
     * Refused once money is committed: §2.5 permits a soft delete only for a
     * genuine data-entry mistake pre-approval, never after disbursement.
     */
    public function cancel(Loan $loan, ?string $reason, User $actor): Loan
    {
        if (! in_array($loan->status, [
            LoanStatus::Draft,
            LoanStatus::MandateFailed,
            LoanStatus::DisbursementFailed,
            LoanStatus::Escalated,
        ], true)) {
            throw LoanStateException::cannotDeleteAfterApproval();
        }

        return DB::transaction(function () use ($loan, $reason, $actor): Loan {
            $this->states->transition($loan, LoanStatus::Cancelled, $actor, $reason);

            $this->audit->log(
                AuditAction::LoanCancelled,
                $loan,
                after: ['reason' => $reason],
                actor: $actor,
            );

            return $loan->fresh();
        });
    }
}
