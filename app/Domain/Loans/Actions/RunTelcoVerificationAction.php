<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Enums\TelcoVerificationStatus;
use App\Domain\Loans\Exceptions\LoanStateException;
use App\Domain\Loans\Services\LoanStateMachine;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Credit review — `POST /vodacom/kyc-verify` (§15.2).
 *
 * The Credit Officer's step. A failure rejects the loan outright rather than
 * parking it: §10 gives `pending_credit_review` exactly two exits, and a
 * telco mismatch means the identity behind the phone number could not be
 * confirmed.
 *
 * Branch scoping is enforced by the caller (§13: "Credit Officer is strictly
 * branch-scoped, no exceptions" — liftable only by the explicit
 * `loans.review_cross_branch` grant).
 */
final class RunTelcoVerificationAction
{
    public function __construct(
        private readonly LoanStateMachine $states,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Loan $loan, bool $passed, User $creditOfficer): Loan
    {
        if ($loan->status !== LoanStatus::PendingCreditReview) {
            throw LoanStateException::notInCreditReview();
        }

        return DB::transaction(function () use ($loan, $passed, $creditOfficer): Loan {
            $loan->loadMissing('customer');

            $loan->telcoVerifications()->create([
                'provider' => 'vodacom',
                'request_payload' => [
                    'phone' => $loan->customer->phone,
                    'nida' => $loan->customer->nida_number,
                ],
                'response_payload' => ['matched' => $passed],
                'status' => $passed ? TelcoVerificationStatus::Success : TelcoVerificationStatus::Failed,
                'verified_at' => Date::now(),
            ]);

            if (! $passed) {
                $this->states->transition($loan, LoanStatus::Rejected, $creditOfficer, 'Telco verification failed');

                /*
                 * The loan has left the approval chain, so it no longer sits at
                 * a stage. Cleared here as well as on the generic decision path
                 * — a stale stage id would keep a rejected application in an
                 * approver's queue count.
                 */
                $loan->update([
                    'rejected_reason' => 'Telco KYC verification failed.',
                    'approval_stage_id' => null,
                ]);

                $this->audit->log(AuditAction::LoanRejected, $loan, after: ['reason' => 'telco_failed'], actor: $creditOfficer);

                return $loan->fresh(['telcoVerifications']);
            }

            $this->states->transition($loan, LoanStatus::PendingFinance, $creditOfficer, 'Telco verification passed');

            // Credit was the last tier; the loan is with Finance now.
            $loan->update(['approval_stage_id' => null]);

            $this->audit->log(AuditAction::LoanTelcoVerified, $loan, after: ['matched' => true], actor: $creditOfficer);

            return $loan->fresh(['telcoVerifications']);
        });
    }
}
