<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Domain\Loans\Enums\EMandateStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Exceptions\LoanStateException;
use App\Domain\Loans\Services\LoanStateMachine;
use App\Domain\Loans\Services\MandateGateway;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The E-Mandate branch of §10 — `POST /bank/e-mandate/verify-otp` (§15.2).
 *
 * A wrong OTP is not merely refused: it marks the mandate failed and moves the
 * loan to `mandate_failed`, which is a state an officer can retry from. That
 * mirrors the frontend, and it means a failed bank authorisation is visible in
 * the loan's history rather than being a silent non-event.
 */
final class VerifyMandateAction
{
    public function __construct(
        private readonly MandateGateway $gateway,
        private readonly LoanStateMachine $states,
        private readonly AuditLogger $audit,
    ) {}

    public function verify(Loan $loan, string $otp, User $actor): Loan
    {
        if ($loan->status !== LoanStatus::MandatePendingOtp) {
            throw LoanStateException::notAwaitingMandateOtp();
        }

        $mandate = $loan->mandates()->where('status', EMandateStatus::PendingOtp)->latest('id')->first();

        if ($mandate === null) {
            throw LoanStateException::notAwaitingMandateOtp();
        }

        return DB::transaction(function () use ($loan, $mandate, $otp, $actor): Loan {
            if (! $this->gateway->verifyOtp($otp)) {
                $mandate->update([
                    'status' => EMandateStatus::Failed,
                    'failure_reason' => 'Incorrect OTP supplied by customer.',
                ]);

                $this->states->transition($loan, LoanStatus::MandateFailed, $actor, 'Mandate OTP verification failed');

                $this->audit->log(
                    AuditAction::LoanMandateFailed,
                    $loan,
                    after: ['mandate_id' => $mandate->getKey()],
                    actor: $actor,
                );

                return $loan->fresh(['mandates']);
            }

            $mandate->update([
                'status' => EMandateStatus::Active,
                'otp_reference' => 'OTP-'.Str::upper(Str::random(10)),
                'verified_at' => Date::now(),
            ]);

            /*
             * §10 routes mandate_active → pending_credit_review, and there is
             * nothing to wait for in between, so both moves happen here. Two
             * history rows rather than one, because the mandate genuinely did
             * become active before the loan moved on.
             */
            $this->states->transition($loan, LoanStatus::MandateActive, $actor, 'Mandate verified');
            $this->states->transition($loan, LoanStatus::PendingCreditReview, $actor, 'Mandate active');

            $this->audit->log(
                AuditAction::LoanMandateVerified,
                $loan,
                after: ['mandate_id' => $mandate->getKey()],
                actor: $actor,
            );

            return $loan->fresh(['mandates']);
        });
    }

    /**
     * Issues a fresh mandate after a failure. The failed row is left intact —
     * a retry is a new attempt, not an erasure of the last one.
     */
    public function retry(Loan $loan, User $actor): Loan
    {
        if ($loan->status !== LoanStatus::MandateFailed) {
            throw LoanStateException::noFailedMandate();
        }

        return DB::transaction(function () use ($loan, $actor): Loan {
            $loan->loadMissing('customer.bankDetails');

            $loan->mandates()->create([
                'bank_name' => $loan->customer->bankDetails->bank_name ?? 'Unknown Bank',
                'status' => EMandateStatus::PendingOtp,
            ]);

            $this->states->transition($loan, LoanStatus::MandatePendingOtp, $actor, 'Mandate retried');

            return $loan->fresh(['mandates']);
        });
    }
}
