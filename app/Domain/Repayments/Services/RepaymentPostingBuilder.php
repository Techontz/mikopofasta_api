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
use App\Support\Percentage;

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
 * plus §5's real-time reserve cut, taken on every interest collection:
 *
 *   Dr Interest Income              (10% of the interest collected)
 *     Cr Reserve Account
 *
 * The reserve cut is two extra lines on the SAME entry rather than a second
 * entry, because it is not an independent event — it is part of recognising
 * that interest. Netting it against the income line instead would hide the
 * gross interest earned, which the P&L needs.
 *
 * One builder, three callers (§7's "three intake channels, one allocation
 * core"): the provider webhook, teller cash, and suspense resolution. The only
 * difference between them is which account is debited, which is why that is a
 * parameter rather than three near-identical builders.
 */
final class RepaymentPostingBuilder
{
    /**
     * §5: "Reserve cut: Dr Interest Income · Cr Reserve Account (real-time, on
     * every interest collection)." The rate mirrors the frontend's
     * RESERVE_RATE of 0.1.
     */
    public const string RESERVE_RATE = '10.000';

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

        $lines = [
            JournalLine::debit(
                (int) $debitAccount->getKey(),
                $allocation->allocatedTotal(),
                $branchId,
                $customerId,
                $loanId,
            ),
        ];

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

        $reserveCut = $this->reserveCut($allocation->totalInterest());

        if ($reserveCut->isPositive()) {
            $lines[] = JournalLine::debit(
                $this->accounts->systemId(SystemAccountCode::InterestIncome),
                $reserveCut,
                $branchId,
                loanId: $loanId,
            );

            $lines[] = JournalLine::credit(
                $this->accounts->systemId(SystemAccountCode::Reserve),
                $reserveCut,
                $branchId,
                loanId: $loanId,
            );
        }

        return $lines;
    }

    /**
     * The reserve taken from an interest collection.
     */
    public function reserveCut(Money $interestCollected): Money
    {
        return $interestCollected->percentage(Percentage::of(self::RESERVE_RATE));
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
