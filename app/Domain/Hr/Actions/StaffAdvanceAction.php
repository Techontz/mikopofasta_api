<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Hr\Enums\StaffAdvanceStatus;
use App\Domain\Hr\Exceptions\StaffAdvanceStateException;
use App\Domain\Hr\Services\PayrollPostingBuilder;
use App\Domain\Hr\Services\SalaryAdvanceCalculator;
use App\Domain\Hr\Services\StaffAdvanceReferenceGenerator;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\LedgerService;
use App\Enums\AuditAction;
use App\Models\SalaryAdvanceCategory;
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
        private readonly SalaryAdvanceCalculator $calculator,
        private readonly StaffAdvanceReferenceGenerator $references,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Step 1 — HR raises the request. Nothing is posted: an advance that has
     * been asked for is not money that has moved.
     */
    public function request(
        StaffProfile $staff,
        Money $amount,
        User $actor,
        ?SalaryAdvanceCategory $category = null,
    ): StaffAdvance {
        /*
         * One advance at a time. Two concurrent advances would each be
         * recovered on their own schedule, and together they could take an
         * employee's salary below zero — the frontend refuses a second request
         * for the same reason.
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

        /*
         * The band is found from the amount rather than chosen by the
         * requester. Letting someone pick their own category would let them
         * pick their own interest rate, and two people borrowing the same
         * amount would be on different terms.
         */
        $category ??= SalaryAdvanceCategory::covering($amount);

        if ($category === null) {
            throw StaffAdvanceStateException::noCategoryForAmount($amount->toDecimalString());
        }

        return DB::transaction(function () use ($staff, $amount, $actor, $category): StaffAdvance {
            /*
             * Terms snapshotted here, at request — the same rule loans follow.
             * Re-pricing the band later must not rewrite an advance already
             * agreed with an employee.
             */
            $interest = $this->calculator->interestOn($amount, $category);

            $advance = StaffAdvance::query()->create([
                'reference' => $this->references->next(),
                'staff_profile_id' => $staff->getKey(),
                'salary_advance_category_id' => $category->getKey(),
                'amount' => $amount->toDecimalString(),
                'interest_amount' => $interest->toDecimalString(),
                'charge_fee' => $category->chargeFee()->toDecimalString(),
                'recovery_periods' => $category->recovery_periods,

                /*
                 * Explicit, not left to the column default. The default applies
                 * on read; the model returned from create() still holds null
                 * for anything not passed, and every caller that asks this
                 * advance what it owes would hit that null first.
                 */
                'amount_recovered' => '0.00',

                'status' => StaffAdvanceStatus::Requested,
                'requested_at' => Date::now(),
            ]);

            $this->audit->log(
                AuditAction::StaffAdvanceRequested,
                $advance,
                after: [
                    'reference' => $advance->reference,
                    'staff_profile_id' => $staff->getKey(),
                    'amount' => $amount->toDecimalString(),
                    'category' => $category->name,
                    'interest_amount' => $interest->toDecimalString(),
                    'charge_fee' => $advance->charge_fee,
                    'recovery_periods' => $advance->recovery_periods,
                ],
                actor: $actor,
            );

            return $advance->load('category');
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

    public function reject(StaffAdvance $advance, User $actor, ?string $reason = null): StaffAdvance
    {
        $this->guardAwaitingDecision($advance);

        return DB::transaction(function () use ($advance, $actor, $reason): StaffAdvance {
            $advance->update([
                'status' => StaffAdvanceStatus::Rejected,
                'approved_by' => $actor->getKey(),
                'approved_at' => Date::now(),
                'rejection_reason' => $reason,
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

            $disbursedAt = Date::now();

            $advance->update([
                'status' => StaffAdvanceStatus::Disbursed,
                'disbursed_at' => $disbursedAt,
                /*
                 * The recovery clock starts when the money leaves, not when the
                 * advance was asked for — an advance approved quickly and
                 * disbursed late is not already overdue.
                 */
                'due_date' => $disbursedAt->copy()->addMonths(max(1, $advance->recovery_periods))->toDateString(),
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
