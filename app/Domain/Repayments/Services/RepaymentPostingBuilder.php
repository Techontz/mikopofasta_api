<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Services;

use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Repayments\DTOs\AllocationResult;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Support\Money;

/**
 * Builds the journal lines for one allocated repayment — spec §5.
 *
 * The canonical posting:
 *
 *   Dr Cash/Bank                    (what was received)
 *     Cr Penalty Income             (penalty component)
 *     Cr Interest Income            (interest component)
 *     Cr Loan Receivable            (principal component)
 *
 * ## The reserve is no longer taken here — Decision Register D1
 *
 * This builder used to add two more lines to every entry, `Dr Interest Income ·
 * Cr Reserve`, at a hardcoded 10% of the interest collected. §5 called it a
 * real-time cut.
 *
 * The client's ruling replaced it: reserve is "calculated from realised profit
 * during the accounting closing process", requires Admin approval to spend, and
 * belongs to Headquarters rather than to any branch. Their reasoning is that the
 * reserve protects capital out of what the business actually earned — "kwenye
 * hiyo faida reserve inatolewa kwanza maana ndo inalinda mtaji" — which is a
 * statement about profit, not about each individual payment.
 *
 * So interest is now recognised gross here, and ClosePeriodAction appropriates
 * the reserve once a month from what survived expenses. See
 * App\Domain\Accounting\Actions\ClosePeriodAction.
 *
 * Two consequences worth knowing:
 *
 *   - Interest Income reads gross during a period. It was previously net of
 *     reserve from the instant of collection.
 *   - Branch profit is therefore gross of reserve too, so the commission engine
 *     deducts it explicitly instead of relying on it having already been netted
 *     out. See CommissionCalculator::computePool().
 *
 * Historical entries carrying the old cut are left exactly as they are. This
 * ledger is reversal-only and history is not rewritten.
 *
 * One builder, three callers (§7's "three intake channels, one allocation
 * core"): the provider webhook, teller cash, and suspense resolution. The only
 * difference between them is which account is debited, which is why that is a
 * parameter rather than three near-identical builders.
 */
final class RepaymentPostingBuilder
{
    public function __construct(private readonly AccountResolver $accounts) {}

    /**
     * @return list<JournalLine>
     */
    public function build(
        Loan $loan,
        AllocationResult $allocation,
        ChartOfAccount $debitAccount,
    ): array {
        $branchId = $loan->branch_id;
        $customerId = $loan->customer_id;
        $loanId = (int) $loan->getKey();

        $lines = [];

        /*
         * Cash in — everything that actually arrived, including any surplus.
         *
         * Note this is the CASH figure, not the allocated total. When part of
         * an installment is settled from an advance credit the borrower paid
         * earlier, that shilling reached the books when it was received;
         * debiting cash for it again would recognise the same money twice.
         */
        $cashIn = $allocation->cashApplied()->add($allocation->unallocated);

        if ($cashIn->isPositive()) {
            $lines[] = JournalLine::debit(
                (int) $debitAccount->getKey(),
                $cashIn,
                $branchId,
                $customerId,
                $loanId,
            );
        }

        /*
         * Advance consumed — the credit is spent, so the liability falls.
         *
         * Dr Customer Advance. The matching credits to income and receivable
         * are the same lines the cash portion produces below; the two sources
         * differ only in what is debited.
         */
        if ($allocation->advanceConsumed->isPositive()) {
            $lines[] = JournalLine::debit(
                $this->accounts->systemId(SystemAccountCode::CustomerAdvance),
                $allocation->advanceConsumed,
                $branchId,
                $customerId,
                $loanId,
            );
        }

        /*
         * Surplus out — money the schedule could not absorb becomes a credit
         * the borrower is owed against future installments.
         *
         * Cr Customer Advance. It is deliberately NOT income: nothing has
         * fallen due to earn it, and recognising it would report profit the
         * lender has not made.
         */
        if ($allocation->unallocated->isPositive()) {
            $lines[] = JournalLine::credit(
                $this->accounts->systemId(SystemAccountCode::CustomerAdvance),
                $allocation->unallocated,
                $branchId,
                $customerId,
                $loanId,
            );
        }

        if ($allocation->totalPenalty()->isPositive()) {
            $lines[] = JournalLine::credit(
                $this->accounts->systemId(SystemAccountCode::PenaltyIncome),
                $allocation->totalPenalty(),
                $branchId,
                loanId: $loanId,
            );
        }

        if ($allocation->totalInterest()->isPositive()) {
            $lines[] = JournalLine::credit(
                $this->accounts->systemId(SystemAccountCode::InterestIncome),
                $allocation->totalInterest(),
                $branchId,
                loanId: $loanId,
            );
        }

        if ($allocation->totalPrincipal()->isPositive()) {
            $lines[] = JournalLine::credit(
                $this->accounts->systemId(SystemAccountCode::LoanReceivable),
                $allocation->totalPrincipal(),
                $branchId,
                loanId: $loanId,
            );
        }

        return $lines;
    }

    /**
     * A held advance being spent on an installment that has reached its due
     * date, with no cash involved at all.
     *
     *   Dr Customer Advance          (the liability falls)
     *     Cr Penalty / Interest Income / Loan Receivable
     *
     * `build()` already produces exactly this when the allocation carries no
     * cash — it emits no debit line for a zero cash figure. So this is that
     * call with the debit account made explicitly irrelevant, rather than a
     * second set of posting rules that could disagree with the first.
     *
     * @return list<JournalLine>
     */
    public function buildAdvanceConsumption(Loan $loan, AllocationResult $allocation): array
    {
        return $this->build(
            $loan,
            $allocation,
            $this->accounts->system(SystemAccountCode::CustomerAdvance),
        );
    }

    /**
     * Where unmatched money sits until someone identifies it — §5's
     * "Unmatched payment: Dr Cash/Bank · Cr Suspense Account".
     *
     * @return list<JournalLine>
     */
    public function buildUnmatched(Money $amount, ChartOfAccount $debitAccount, ?int $branchId): array
    {
        return [
            JournalLine::debit((int) $debitAccount->getKey(), $amount, $branchId),
            JournalLine::credit($this->accounts->systemId(SystemAccountCode::Suspense), $amount, $branchId),
        ];
    }

    /**
     * Resolving a suspense item draws SUSPENSE down instead of debiting cash —
     * the cash debit already happened when the money arrived. §5: "on
     * resolution: Dr Suspense · Cr Loan (via a **new** entry, never editing
     * the original)."
     *
     * @return list<JournalLine>
     */
    public function buildSuspenseResolution(Loan $loan, AllocationResult $allocation): array
    {
        return $this->build(
            $loan,
            $allocation,
            $this->accounts->system(SystemAccountCode::Suspense),
        );
    }
}
