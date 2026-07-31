<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Hr\Enums\StaffAdvanceStatus;
use App\Domain\Hr\Exceptions\StaffAdvanceStateException;
use App\Domain\Hr\Services\PayrollPostingBuilder;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\LedgerService;
use App\Enums\AuditAction;
use App\Models\StaffAdvance;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The staff advance lifecycle — §11 and §15.5.
 *
 * request → approve (HR) → disburse (**Finance**, never HR) → recovered by a
 * payroll deduction.
 *
 * The split between approving and disbursing is the control §11 insists on,
 * and it is the same principle as loan approval and ledger reversal: the
 * person who says an advance is warranted must not also be the person who
 * moves the money. It is enforced by two different permissions on two
 * different endpoints, not by hiding a button.
 *
 * The four steps live in one class because they are one workflow over one
 * record; splitting them across four files would scatter the state machine
 * that is the whole point.
 */
final class StaffAdvanceAction
{
    public function __construct(
        private readonly PayrollPostingBuilder $postings,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Step 1 — HR raises the request. Nothing is posted: an advance that has
     * been asked for is not money that has moved.
     */
    public function request(StaffProfile $staff, Money $amount, User $actor): StaffAdvance
    {
        /*
         * One advance at a time. Two concurrent advances would both be
         * recovered at the flat rate, which could take an employee's salary
         * below zero — and the frontend refuses a second request for the same
         * reason.
         */
        $inProgress = $staff->advances()
            ->whereIn('status', [
                StaffAdvanceStatus::Requested->value,
                StaffAdvanceStatus::Approved->value,
                StaffAdvanceStatus::Disbursed->value,
            ])
            ->exists();

        if ($inProgress) {
            throw StaffAdvanceStateException::alreadyInProgress();
        }

        return DB::transaction(function () use ($staff, $amount, $actor): StaffAdvance {
            $advance = StaffAdvance::query()->create([
                'staff_profile_id' => $staff->getKey(),
                'amount' => $amount->toDecimalString(),
                'status' => StaffAdvanceStatus::Requested,
                'requested_at' => Date::now(),
            ]);

            $this->audit->log(
                AuditAction::StaffAdvanceRequested,
                $advance,
                after: [
                    'staff_profile_id' => $staff->getKey(),
                    'amount' => $amount->toDecimalString(),
                ],
                actor: $actor,
            );

            return $advance;
        });
    }

    /**
     * Step 2 — HR approves. Still nothing posted; §11 gives disbursement to
     * Finance.
     */
    public function approve(StaffAdvance $advance, User $actor): StaffAdvance
    {
        $this->guardAwaitingDecision($advance);

        return DB::transaction(function () use ($advance, $actor): StaffAdvance {
            $advance->update([
                'status' => StaffAdvanceStatus::Approved,
                'approved_by' => $actor->getKey(),
                'approved_at' => Date::now(),
            ]);

            $this->audit->log(
                AuditAction::StaffAdvanceApproved,
                $advance,
                after: ['approved_by' => $actor->getKey()],
                actor: $actor,
            );

            return $advance->fresh();
        });
    }

    public function reject(StaffAdvance $advance, User $actor): StaffAdvance
    {
        $this->guardAwaitingDecision($advance);

        return DB::transaction(function () use ($advance, $actor): StaffAdvance {
            $advance->update([
                'status' => StaffAdvanceStatus::Rejected,
                'approved_by' => $actor->getKey(),
                'approved_at' => Date::now(),
            ]);

            $this->audit->log(
                AuditAction::StaffAdvanceRejected,
                $advance,
                after: ['decided_by' => $actor->getKey()],
                actor: $actor,
            );

            return $advance->fresh();
        });
    }

    /**
     * Step 3 — Finance disburses, and this is where the ledger is touched:
     * Dr Staff Advance Receivable · Cr Staff Fund.
     *
     * The advance is lent out of the fund the staff themselves contribute to,
     * and the payroll deduction credits it straight back — the two postings
     * are mirror images, which is what makes the fund's balance mean something.
     */
    public function disburse(StaffAdvance $advance, User $actor): StaffAdvance
    {
        if ($advance->status !== StaffAdvanceStatus::Approved) {
            throw StaffAdvanceStateException::notApproved();
        }

        $advance->loadMissing('staffProfile');

        return DB::transaction(function () use ($advance, $actor): StaffAdvance {
            $entry = $this->ledger->post(
                description: sprintf('Staff salary advance — %s', $advance->staffProfile->displayName()),
                sourceType: JournalSourceType::StaffAdvance,
                sourceId: (int) $advance->getKey(),
                lines: $this->postings->buildAdvanceDisbursement(
                    $advance->amountMoney(),
                    (int) $advance->staff_profile_id,
                    $advance->staffProfile->branch_id,
                ),
                postedBy: $actor,
            );

            $advance->update([
                'status' => StaffAdvanceStatus::Disbursed,
                'disbursed_at' => Date::now(),
                'journal_entry_id' => $entry->getKey(),
            ]);

            $this->audit->log(
                AuditAction::StaffAdvanceDisbursed,
                $advance,
                after: [
                    'journal_entry' => $entry->entry_number,
                    'amount' => $advance->amount,
                ],
                actor: $actor,
            );

            return $advance->fresh(['journalEntry']);
        });
    }

    private function guardAwaitingDecision(StaffAdvance $advance): void
    {
        if ($advance->status !== StaffAdvanceStatus::Requested) {
            throw StaffAdvanceStateException::notAwaitingDecision();
        }
    }
}
