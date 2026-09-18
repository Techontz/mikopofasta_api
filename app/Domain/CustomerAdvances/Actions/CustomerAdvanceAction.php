<?php

declare(strict_types=1);

namespace App\Domain\CustomerAdvances\Actions;

use App\Domain\CustomerAdvances\Enums\CustomerAdvanceStatus;
use App\Domain\CustomerAdvances\Exceptions\CustomerAdvanceStateException;
use App\Domain\CustomerAdvances\Services\CustomerAdvanceCalculator;
use App\Domain\CustomerAdvances\Services\CustomerAdvanceFunding;
use App\Domain\CustomerAdvances\Services\CustomerAdvancePostingBuilder;
use App\Domain\CustomerAdvances\Services\CustomerAdvanceReferenceGenerator;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\LedgerService;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\CustomerAdvance;
use App\Models\SalaryAdvanceCategory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The customer salary advance lifecycle.
 *
 * request → approve → disburse (the money moves here and nowhere earlier) →
 * collected by CollectCustomerAdvanceAction until it settles.
 *
 * Approving and disbursing are separate steps behind separate permissions, the
 * same control the staff advance, the loan book and every ledger reversal in
 * this system share: whoever says an advance is warranted must not also be the
 * person who moves the money.
 *
 * The four steps live in one class because they are one workflow over one
 * record — splitting them across four files would scatter the state machine
 * that is the whole point.
 */
final class CustomerAdvanceAction
{
    public function __construct(
        private readonly CustomerAdvanceCalculator $calculator,
        private readonly CustomerAdvancePostingBuilder $postings,
        private readonly CustomerAdvanceFunding $funding,
        private readonly CustomerAdvanceReferenceGenerator $references,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Step 1 — the request. Nothing is posted: an advance that has been asked
     * for is not money that has moved.
     */
    public function request(
        Customer $customer,
        Money $amount,
        User $actor,
        ?SalaryAdvanceCategory $category = null,
    ): CustomerAdvance {
        if ($customer->branch_id === null) {
            throw CustomerAdvanceStateException::customerHasNoBranch();
        }

        /*
         * One advance at a time. Two running together would each be collected
         * on their own terms against the same salary, and the second would be
         * lending against money the first has already claimed.
         */
        $inProgress = CustomerAdvance::query()
            ->where('customer_id', $customer->getKey())
            ->whereIn('status', array_map(
                static fn (CustomerAdvanceStatus $s): string => $s->value,
                array_filter(
                    CustomerAdvanceStatus::cases(),
                    static fn (CustomerAdvanceStatus $s): bool => $s->isOpen(),
                ),
            ))
            ->exists();

        if ($inProgress) {
            throw CustomerAdvanceStateException::alreadyInProgress();
        }

        /*
         * The band is found from the amount rather than chosen by whoever types
         * the request. Letting them pick the category would let them pick the
         * interest rate, and two customers borrowing the same amount would be
         * on different terms.
         */
        $category ??= SalaryAdvanceCategory::covering($amount);

        if ($category === null) {
            throw CustomerAdvanceStateException::noCategoryForAmount($amount->toDecimalString());
        }

        return DB::transaction(function () use ($customer, $amount, $actor, $category): CustomerAdvance {
            // Terms snapshotted here, at request. Re-pricing the band later
            // must not rewrite an advance already agreed with a customer.
            $interest = $this->calculator->interestOn($amount, $category);

            $advance = CustomerAdvance::query()->create([
                'reference' => $this->references->nextAdvance(),
                'customer_id' => $customer->getKey(),
                'branch_id' => $customer->branch_id,
                'salary_advance_category_id' => $category->getKey(),
                'amount' => $amount->toDecimalString(),
                'interest_amount' => $interest->toDecimalString(),
                'charge_fee' => $category->chargeFee()->toDecimalString(),
                'recovery_periods' => $category->recovery_periods,

                /*
                 * Explicit rather than left to the column defaults. The
                 * defaults apply on read; the model returned from create()
                 * still holds null for anything not passed, and the first
                 * caller to ask this advance what it owes would hit that null.
                 */
                'amount_repaid' => '0.00',
                'principal_repaid' => '0.00',
                'interest_repaid' => '0.00',
                'fee_repaid' => '0.00',

                'status' => CustomerAdvanceStatus::Requested,
                'requested_at' => Date::now(),
                'requested_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::CustomerAdvanceRequested,
                $advance,
                after: [
                    'reference' => $advance->reference,
                    'customer_id' => $customer->getKey(),
                    'amount' => $amount->toDecimalString(),
                    'category' => $category->name,
                    'interest_amount' => $interest->toDecimalString(),
                    'charge_fee' => $advance->charge_fee,
                    'recovery_periods' => $advance->recovery_periods,
                ],
                actor: $actor,
            );

            return $advance->load(CustomerAdvance::LIST_RELATIONS);
        });
    }

    /** Step 2 — approved. Still nothing posted; disbursement moves the money. */
    public function approve(CustomerAdvance $advance, User $actor): CustomerAdvance
    {
        $this->guardAwaitingDecision($advance);

        return DB::transaction(function () use ($advance, $actor): CustomerAdvance {
            $advance->update([
                'status' => CustomerAdvanceStatus::Approved,
                'approved_by' => $actor->getKey(),
                'approved_at' => Date::now(),
            ]);

            $this->audit->log(
                AuditAction::CustomerAdvanceApproved,
                $advance,
                after: ['approved_by' => $actor->getKey()],
                actor: $actor,
            );

            return $advance->fresh(CustomerAdvance::LIST_RELATIONS);
        });
    }

    public function reject(CustomerAdvance $advance, User $actor, ?string $reason = null): CustomerAdvance
    {
        $this->guardAwaitingDecision($advance);

        return DB::transaction(function () use ($advance, $actor, $reason): CustomerAdvance {
            $advance->update([
                'status' => CustomerAdvanceStatus::Rejected,
                'approved_by' => $actor->getKey(),
                'approved_at' => Date::now(),
                'rejection_reason' => $reason,
            ]);

            $this->audit->log(
                AuditAction::CustomerAdvanceRejected,
                $advance,
                after: ['decided_by' => $actor->getKey(), 'reason' => $reason],
                actor: $actor,
            );

            return $advance->fresh(CustomerAdvance::LIST_RELATIONS);
        });
    }

    /**
     * Step 3 — the money leaves.
     *
     *   Dr 1250 Salary Advance Receivable · Cr the funding account
     *
     * The funding account is a real one — a company bank account or the branch
     * till — which is what makes the client's "there is no balance sitting
     * under Salary Advance" true rather than merely asserted.
     */
    public function disburse(
        CustomerAdvance $advance,
        User $actor,
        ?int $bankAccountId = null,
        bool $fromCash = false,
    ): CustomerAdvance {
        if ($advance->status !== CustomerAdvanceStatus::Approved) {
            throw CustomerAdvanceStateException::notApproved();
        }

        $advance->loadMissing(['customer', 'branch']);

        ['account' => $fundingAccount] = $this->funding->choose($advance->branch, $bankAccountId, $fromCash);

        return DB::transaction(function () use ($advance, $actor, $fundingAccount): CustomerAdvance {
            $entry = $this->ledger->post(
                description: sprintf(
                    'Salary advance %s — %s',
                    $advance->reference,
                    $advance->customer?->fullName() ?? 'customer',
                ),
                sourceType: JournalSourceType::CustomerAdvanceIssue,
                sourceId: (int) $advance->getKey(),
                lines: $this->postings->buildIssue($advance, $fundingAccount),
                postedBy: $actor,
            );

            $disbursedAt = Date::now();

            $advance->update([
                'status' => CustomerAdvanceStatus::Disbursed,
                'disbursed_by' => $actor->getKey(),
                'disbursed_at' => $disbursedAt,
                /*
                 * The clock starts when the money leaves, not when the advance
                 * was asked for — one approved quickly and disbursed late is
                 * not already overdue.
                 */
                'due_date' => $disbursedAt->copy()
                    ->addMonths(max(1, $advance->recovery_periods))
                    ->toDateString(),
                'funding_account_id' => $fundingAccount->getKey(),
                'journal_entry_id' => $entry->getKey(),
            ]);

            $this->audit->log(
                AuditAction::CustomerAdvanceDisbursed,
                $advance,
                after: [
                    'journal_entry' => $entry->entry_number,
                    'amount' => $advance->amount,
                    'funded_from' => $fundingAccount->name,
                ],
                actor: $actor,
            );

            return $advance->fresh([...CustomerAdvance::LIST_RELATIONS, 'journalEntry']);
        });
    }

    private function guardAwaitingDecision(CustomerAdvance $advance): void
    {
        if ($advance->status !== CustomerAdvanceStatus::Requested) {
            throw CustomerAdvanceStateException::notAwaitingDecision();
        }
    }
}
