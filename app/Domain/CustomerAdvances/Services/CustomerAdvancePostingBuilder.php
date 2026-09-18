<?php

declare(strict_types=1);

namespace App\Domain\CustomerAdvances\Services;

use App\Domain\CustomerAdvances\DTOs\AdvanceSplit;
use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Models\ChartOfAccount;
use App\Models\CustomerAdvance;
use App\Support\Money;

/**
 * The two postings a customer salary advance ever makes.
 *
 * ## The client's rule
 *
 * > "wakati wa maombi itatoka kwenye Principal Operation na wakati wa malipo
 * > sehemu ya mtaji itarudi Principal Operation na faida itaingia Income
 * > Operation"
 *
 * Issue — the operational money goes out and the customer owes it:
 *
 *   Dr 1250 Salary Advance Receivable      the principal
 *     Cr Bank / Branch Teller Cash         the principal
 *
 * Payment — the capital comes back to the same operational money, and only the
 * profit is recognised:
 *
 *   Dr Bank / Branch Teller Cash           what was collected
 *     Cr 1250 Salary Advance Receivable    the capital portion
 *     Cr 2000 Interest Income              the interest portion
 *     Cr 2100 Fee Income                   the charge-fee portion
 *
 * ## Why nothing is posted to 1100 Principal
 *
 * The obvious reading of "Principal Operation" is the 1100 Principal account,
 * and that reading is wrong in a way that costs real money. 1100 is EQUITY: it
 * holds reinvested profit, and crediting it at disbursement is precisely the
 * double count this ledger already removed once — the money is still in the
 * bank AND now a receivable as well. See SystemAccountCode::type().
 *
 * What the client means by Principal Operation is the operational money the
 * business lends from, and in this chart that is the bank account or the branch
 * till the payout actually leaves. So the capital goes out of it and comes back
 * into it, exactly as stated, and equity moves only when profit is earned —
 * which is the second half of the same rule.
 *
 * ## Why 1250 rather than an account called Salary Advance
 *
 * The client was explicit that there is no such pot: "there is no TZS 1,000,000
 * sitting in a Salary Advance Account… Salary Advance = dashboard summary +
 * transaction history, NOT a separate cash account." 1250 is a RECEIVABLE — the
 * debt the customer owes, which is a different thing from cash held, and it
 * falls to zero as they pay. The dashboard's Salary Advance figures are read
 * from the register, not from a balance.
 *
 * Note what is NOT here: no penalty line, and no fee withheld at disbursement.
 * The charge fee is part of what is collected, like the staff advance's and
 * unlike a loan fee, and an advance carries no penalty at all.
 */
final class CustomerAdvancePostingBuilder
{
    public function __construct(private readonly AccountResolver $accounts) {}

    /**
     * Issue: the principal leaves the funding account and becomes a debt.
     *
     * The full principal, with nothing withheld — the customer receives what
     * they asked for and owes it plus the agreed interest and fee.
     *
     * @return list<JournalLine>
     */
    public function buildIssue(CustomerAdvance $advance, ChartOfAccount $fundingAccount): array
    {
        $principal = $advance->amountMoney();
        $branchId = $advance->branch_id;
        $customerId = (int) $advance->customer_id;

        return [
            JournalLine::debit(
                $this->accounts->systemId(SystemAccountCode::CustomerAdvanceReceivable),
                $principal,
                $branchId,
                $customerId,
            ),
            JournalLine::credit(
                (int) $fundingAccount->getKey(),
                $principal,
                $branchId,
                $customerId,
            ),
        ];
    }

    /**
     * Payment: capital back to the operational money, profit to income.
     *
     * Each credit is emitted only when it is positive. A payment small enough
     * that its interest share rounds to nothing must not try to post a zero
     * line — LedgerService rejects those, and rightly: a zero line is either a
     * calculation that produced nothing or a sign error.
     *
     * @return list<JournalLine>
     */
    public function buildPayment(
        CustomerAdvance $advance,
        AdvanceSplit $split,
        ChartOfAccount $cashAccount,
    ): array {
        $branchId = $advance->branch_id;
        $customerId = (int) $advance->customer_id;

        $lines = [
            JournalLine::debit(
                (int) $cashAccount->getKey(),
                $split->total(),
                $branchId,
                $customerId,
            ),
        ];

        $credit = function (SystemAccountCode $code, Money $amount) use (&$lines, $branchId, $customerId): void {
            if ($amount->isPositive()) {
                $lines[] = JournalLine::credit(
                    $this->accounts->systemId($code),
                    $amount,
                    $branchId,
                    $customerId,
                );
            }
        };

        // Capital first, in the order the rule states it.
        $credit(SystemAccountCode::CustomerAdvanceReceivable, $split->principal);
        $credit(SystemAccountCode::InterestIncome, $split->interest);
        $credit(SystemAccountCode::FeeIncome, $split->fee);

        return $lines;
    }
}
