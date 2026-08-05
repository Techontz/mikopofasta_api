<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Exceptions\LoanStateException;
use App\Models\Loan;
use App\Models\User;

/**
 * POST /loans/{loan}/approve-manager — the branch manager's decision (§15.2).
 *
 * ## What changed, and why this class still exists
 *
 * The chain the client specified has four tiers, not one:
 *
 *     Loan Officer → Branch Manager → Zone Manager → Head Office Credit → Disbursement
 *
 * so the manager's approval is now stage ONE of a chain rather than the whole
 * of it, and every stage offers the same four decisions. All of that lives in
 * RecordApprovalDecisionAction, which is the single implementation.
 *
 * This class is kept as the manager stage's named entry point because the
 * existing route, the existing frontend action and the existing tests all speak
 * to it. It delegates and adds one thing of its own: the guard that this
 * endpoint is only for the manager stage. Without it, `approve-manager` would
 * quietly clear a zone or credit decision for anyone who happened to hold the
 * right permission, which is not what the route says it does.
 */
final class DecideLoanApprovalAction
{
    public function __construct(private readonly RecordApprovalDecisionAction $decisions) {}

    public function approve(Loan $loan, User $manager): Loan
    {
        $this->guardManagerStage($loan);

        return $this->decisions->approve($loan, $manager);
    }

    public function reject(Loan $loan, string $reason, User $manager): Loan
    {
        $this->guardManagerStage($loan);

        return $this->decisions->reject($loan, $manager, $reason);
    }

    /**
     * This endpoint decides the manager stage and nothing else.
     *
     * The generic `/approval/decide` route is what the later stages use; a
     * separate guard here keeps each route honest about which decision it takes.
     */
    private function guardManagerStage(Loan $loan): void
    {
        if ($loan->status !== LoanStatus::PendingManagerApproval) {
            throw LoanStateException::notAwaitingManagerApproval();
        }
    }
}
