<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Hr\Enums\StaffLoanStatus;
use App\Domain\Hr\Exceptions\StaffLoanStateException;
use App\Domain\Hr\Services\PayrollPostingBuilder;
use App\Domain\Hr\Services\StaffLoanReferenceGenerator;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\LedgerService;
use App\Enums\AuditAction;
use App\Models\StaffLoan;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The staff loan lifecycle — §14 and §16.7–16.8 of the HR document.
 *
 * request → approve (HR) → disburse (**Finance**, never HR) → recovered by a
 * payroll deduction until it closes.
 *
 * ## Why this class did not exist before
 *
 * `staff_loans` had a table, a model, a read endpoint and a seeder that wrote
 * one row directly. There was no way to create a staff loan through the
 * application at all — and no way for one to end, either, because nothing
 * assigned `StaffLoanStatus::Closed`. See `RecoverStaffLoanAction` for what
 * that cost.
 *
 * The shape is `StaffAdvanceAction`'s deliberately. §16.7 and §16.8 apply to
 * staff money generally — *"Malipo yote HR ata-approval"*, *"disbursement zote
 * zitafanyika finance"* — so an advance and a loan take the same route, and two
 * different routes for the same rule would be two places for it to drift.
 *
 * ## Where the money comes from
 *
 * §12 and §14 both say the Staff Fund: *Dr Staff Loan, Cr Staff Fund*. The fund
 * is what the employees have collectively contributed out of their salaries,
 * so a loan is lent out of it and recovered back into it — the disbursement and
 * the payroll deduction are mirror images, which is what makes the fund's
 * balance mean anything.
 */
final class StaffLoanAction
{
    public function __construct(
        private readonly PayrollPostingBuilder $postings,
        private readonly LedgerService $ledger,
        private readonly StaffLoanReferenceGenerator $references,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Step 1 — the request. Nothing is posted: a loan that has been asked for
     * is not money that has moved.
     */
    public function request(StaffProfile $staff, Money $amount, int $recoveryPeriods, User $actor): StaffLoan
    {
        /*
         * One loan at a time. Two concurrent loans would each be recovered on
         * their own schedule, and together they could take an employee's salary
         * below zero — which is the same reason an advance refuses a second
         * request.
         */
        $inProgress = $staff->loans()
            ->whereIn('status', [
                StaffLoanStatus::Requested->value,
                StaffLoanStatus::Approved->value,
                StaffLoanStatus::Active->value,
            ])
            ->exists();

        if ($inProgress) {
            throw StaffLoanStateException::alreadyInProgress();
        }

        return DB::transaction(function () use ($staff, $amount, $recoveryPeriods, $actor): StaffLoan {
            $loan = StaffLoan::query()->create([
                'reference' => $this->references->next(),
                'staff_profile_id' => $staff->getKey(),
                'amount' => $amount->toDecimalString(),

                /*
                 * Explicit, not left to the column default. The default applies
                 * on read; the model returned from create() still holds null
                 * for anything not passed, and every caller that asks this loan
                 * what it owes would hit that null first. The advance had
                 * exactly this bug.
                 */
                'amount_recovered' => '0.00',

                'recovery_periods' => $recoveryPeriods,
                'status' => StaffLoanStatus::Requested,
                'requested_at' => Date::now(),
                'requested_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::StaffLoanRequested,
                $loan,
                after: [
                    'reference' => $loan->reference,
                    'staff_profile_id' => $staff->getKey(),
                    'amount' => $loan->amount,
                    'recovery_periods' => $recoveryPeriods,
                ],
                actor: $actor,
            );

            return $loan;
        });
    }

    /** Step 2 — HR's decision. Still nothing posted. */
    public function approve(StaffLoan $loan, User $actor): StaffLoan
    {
        $this->guardStatus($loan, StaffLoanStatus::Requested);

        return DB::transaction(function () use ($loan, $actor): StaffLoan {
            $loan->update([
                'status' => StaffLoanStatus::Approved,
                'approved_by' => $actor->getKey(),
                'approved_at' => Date::now(),
            ]);

            $this->audit->log(
                AuditAction::StaffLoanApproved,
                $loan,
                before: ['status' => StaffLoanStatus::Requested->value],
                after: ['status' => $loan->status->value, 'approved_by' => $actor->getKey()],
                actor: $actor,
            );

            return $loan;
        });
    }

    public function reject(StaffLoan $loan, ?string $reason, User $actor): StaffLoan
    {
        $this->guardStatus($loan, StaffLoanStatus::Requested);

        return DB::transaction(function () use ($loan, $reason, $actor): StaffLoan {
            $loan->update([
                'status' => StaffLoanStatus::Rejected,
                'approved_by' => $actor->getKey(),
                'approved_at' => Date::now(),
                'rejection_reason' => $reason,
            ]);

            $this->audit->log(
                AuditAction::StaffLoanRejected,
                $loan,
                before: ['status' => StaffLoanStatus::Requested->value],
                after: ['status' => $loan->status->value, 'rejection_reason' => $reason],
                actor: $actor,
            );

            return $loan;
        });
    }

    /**
     * Step 3 — Finance releases the money, and the only step that posts.
     *
     *     Dr 7010 Staff Loan Receivable
     *       Cr 7000 Staff Fund
     *
     * §16.8 gives this to Finance and never to HR, which is enforced by the
     * permission on the endpoint rather than by hiding a button.
     */
    public function disburse(StaffLoan $loan, User $actor): StaffLoan
    {
        $this->guardStatus($loan, StaffLoanStatus::Approved);

        $amount = $loan->amountMoney();

        /*
         * There is deliberately NO "can the fund afford it" check here, and the
         * reason is an unresolved ambiguity in the source document rather than
         * an omission.
         *
         * §12 heads its list "📤 USAGE" — the fund being drawn down — and then
         * writes the entry as *Dr Staff Advance / Cr Staff Fund*. Those two
         * disagree: crediting a liability raises it, so the documented posting
         * makes the fund grow every time it lends. `7000 Staff Fund` therefore
         * measures contributions **plus everything ever lent**, not what is
         * available to lend, and a sufficiency check against it would compare
         * an amount to a number that does not mean what the check assumes.
         *
         * The postings are left exactly as the document writes them. Changing
         * the direction would alter six modules' worth of settled ledger
         * history on the strength of one reading of one heading, and the
         * trial balance is unaffected either way — it is a question of what the
         * balance *means*, not of whether the books balance.
         *
         * Flagged for the owner in docs/modules/hr-payroll.md. If the intended
         * reading is that lending draws the fund down, the change is two lines
         * in PayrollPostingBuilder and this guard becomes both correct and
         * worth having.
         */

        return DB::transaction(function () use ($loan, $amount, $actor): StaffLoan {
            $staff = $loan->staffProfile;

            $entry = $this->ledger->post(
                description: sprintf('Staff loan disbursement — %s', $staff->displayName()),
                sourceType: JournalSourceType::StaffLoan,
                sourceId: (int) $loan->getKey(),
                lines: $this->postings->buildLoanDisbursement(
                    $amount,
                    (int) $staff->getKey(),
                    $staff->branch_id,
                ),
                postedBy: $actor,
            );

            $loan->update([
                'status' => StaffLoanStatus::Active,
                'disbursed_at' => Date::now()->toDateString(),
                'disbursed_by' => $actor->getKey(),
                'journal_entry_id' => $entry->getKey(),
            ]);

            $this->audit->log(
                AuditAction::StaffLoanDisbursed,
                $loan,
                before: ['status' => StaffLoanStatus::Approved->value],
                after: [
                    'status' => $loan->status->value,
                    'journal_entry_id' => $entry->getKey(),
                    'amount' => $loan->amount,
                ],
                actor: $actor,
            );

            return $loan;
        });
    }

    private function guardStatus(StaffLoan $loan, StaffLoanStatus $expected): void
    {
        if ($loan->status !== $expected) {
            throw StaffLoanStateException::wrongStatus($loan->status, $expected);
        }
    }
}
