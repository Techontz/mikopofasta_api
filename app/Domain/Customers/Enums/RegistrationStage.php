<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/**
 * How far a registration has got, in the words the branch uses.
 *
 * DERIVED, NEVER STORED. There is no `registration_stage` column and there
 * must not be one: every state below is already fully determined by facts the
 * database holds — the KYC requirement set, the face scan, the account status,
 * the approval decision. A column would be a second opinion that drifts from
 * the first the moment anything is updated by a path that forgets to write it,
 * and the customer's true state would then depend on which of the two you read.
 *
 * This is the same rule `kyc_status` follows through KycEvaluator::refresh(),
 * one level up: `kyc_status` is a cached conclusion the evaluator owns, and
 * this enum is a reading of that conclusion together with the account's
 * standing. See RegistrationProgress.
 */
enum RegistrationStage: string
{
    /**
     * Typed but never submitted — a `customer_registration_drafts` row. The
     * only stage that does not describe a Customer, because at this point
     * there is no customer.
     */
    case Draft = 'draft';

    /** Saved, but something the account type requires is still missing. */
    case InformationIncomplete = 'information_incomplete';

    /**
     * Everything else is done; only the liveness check remains.
     *
     * The stage this whole workflow exists to make possible: the officer
     * finishes and saves at the counter, and the face scan happens afterwards,
     * from whatever device is to hand.
     */
    case AwaitingFaceVerification = 'awaiting_face_verification';

    /**
     * Everything the account type asks for is captured and the face scan has
     * passed — now a manager must approve the registration.
     *
     * The stage this workflow was missing. Completing KYC used to make a
     * customer borrowable outright; it now makes them ready to be looked at.
     */
    case AwaitingRegistrationApproval = 'awaiting_registration_approval';

    /** A manager refused the registration. Correct it and send it back. */
    case RegistrationRejected = 'registration_rejected';

    /** Every required item satisfied — but the account cannot borrow. */
    case NotEligible = 'not_eligible';

    /** Complete, in good standing, and ready to apply for a loan. */
    case LoanEligible = 'loan_eligible';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Registration draft',
            self::InformationIncomplete => 'Registration incomplete',
            self::AwaitingFaceVerification => 'Awaiting face verification',
            self::AwaitingRegistrationApproval => 'Awaiting registration approval',
            self::RegistrationRejected => 'Registration returned for correction',
            self::NotEligible => 'KYC complete — not eligible',
            self::LoanEligible => 'KYC complete — eligible for loan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
