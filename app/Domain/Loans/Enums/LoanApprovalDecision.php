<?php

declare(strict_types=1);

namespace App\Domain\Loans\Enums;

/**
 * What an approver can do at any stage of the chain — the client's four
 * decisions, plus the one that undoes a hold.
 *
 * Distinct cases rather than a boolean and a reason string, because they are
 * genuinely different outcomes for the applicant: a rejection ends the
 * application, a return sends it back to be fixed, and a hold pauses it with
 * nothing changed. Collapsing any two of them would make "how many
 * applications did we actually turn down last quarter" unanswerable.
 */
enum LoanApprovalDecision: string
{
    /** Cleared this stage; the loan moves to the next one. */
    case Approved = 'approved';

    /** Declined outright. Terminal — the application is over. */
    case Rejected = 'rejected';

    /**
     * Sent back to the originating officer to correct and resubmit.
     *
     * Not a rejection. The loan re-enters the chain from the first stage once
     * resubmitted, and any schedule generated earlier is discarded — terms that
     * may have changed must not leave a stale plan behind.
     */
    case ReturnedForModification = 'returned_for_modification';

    /**
     * Paused where it stands, pending something outside the system — a document,
     * a site visit, a conversation with the customer.
     *
     * The loan keeps its place in the chain; releasing it puts it back exactly
     * where it was rather than restarting the approval.
     */
    case Held = 'held';

    /** Taken off hold and returned to the stage it was held at. */
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::ReturnedForModification => 'Returned for Modification',
            self::Held => 'Held',
            self::Released => 'Released from Hold',
        };
    }

    /**
     * Whether the decision must be explained.
     *
     * Rejecting, returning and holding must be. An applicant told only
     * "rejected", an officer told only "returned", or a queue showing a loan
     * held for three weeks with no note are each a decision nobody can answer
     * or appeal.
     *
     * Approving and releasing need not be. An approval speaks for itself, and a
     * release is the END of a hold whose reason is already on the record —
     * demanding a second explanation for undoing it would be paperwork rather
     * than accountability.
     */
    public function requiresReason(): bool
    {
        return ! in_array($this, [self::Approved, self::Released], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $d): string => $d->value, self::cases());
    }
}
