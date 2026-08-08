<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Enums\DeductionType;
use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Models\ChartOfAccount;
use App\Models\Deduction;
use App\Models\PayrollLine;
use App\Models\StaffAdvance;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Builds the journal lines for payroll and staff advances — spec §5 and §11.
 *
 * Payroll reaches the ledger as three separate entries per employee, and the
 * separation is the point: each answers a different question and each happens
 * at a different moment.
 *
 *   1. **Recognition** (at finalization) — the cost is incurred and a debt to
 *      the employee is created:
 *
 *          Dr Salary Expense        (base + allowances)
 *          Dr Commission Expense    (commission, when there is any)
 *            Cr Staff Payable       (the gross owed)
 *
 *   2. **Deductions** (at finalization) — what the employee owes back reduces
 *      that debt and lands in the fund or receivable it belongs to:
 *
 *          Dr Staff Payable         (total deducted)
 *            Cr Staff Fund          (the 10% contribution)
 *            Cr Staff Loan Receivable
 *            Cr Staff Advance Receivable
 *
 *   3. **Payment** (when Finance executes it) — the remaining debt is settled:
 *
 *          Dr Staff Payable         (net salary)
 *            Cr Bank
 *
 * Staff Payable is what makes the three cohere: recognition credits it,
 * deductions and payment debit it, and once a run is paid it nets to zero for
 * that employee. Collapsing them into one entry would lose the period between
 * "we owe this" and "we have paid it", which is the whole reason a payable
 * account exists.
 *
 * Commission is deliberately expensed here and nowhere else. §11 computes a
 * pool per branch, but the pool is an entitlement rather than a transaction —
 * posting it separately as well would recognise the same money twice.
 */
final class PayrollPostingBuilder
{
    public function __construct(private readonly AccountResolver $accounts) {}

    /**
     * Entry 1 — recognise the cost and the debt.
     *
     * @return list<JournalLine>
     */
    public function buildRecognition(PayrollLine $line): array
    {
        $staff = $line->staffProfile;
        $staffId = (int) $staff->getKey();
        $branchId = $staff->branch_id;

        $lines = [];

        // Base pay and allowances are one salary cost; only commission is
        // separated, because §5 gives it its own expense account and a branch
        // P&L needs to show what its performance actually cost.
        $salaryCost = $line->baseSalary()->add($line->allowancesTotal());

        if ($salaryCost->isPositive()) {
            $lines[] = JournalLine::debit(
                $this->accounts->systemId(SystemAccountCode::SalaryExpense),
                $salaryCost,
                $branchId,
                staffProfileId: $staffId,
            );
        }

        if ($line->commissionAmount()->isPositive()) {
            $lines[] = JournalLine::debit(
                $this->accounts->systemId(SystemAccountCode::CommissionExpense),
                $line->commissionAmount(),
                $branchId,
                staffProfileId: $staffId,
            );
        }

        $lines[] = JournalLine::credit(
            $this->accounts->systemId(SystemAccountCode::StaffPayable),
            $line->grossPay(),
            $branchId,
            staffProfileId: $staffId,
        );

        return $lines;
    }

    /**
     * Entry 2 — deductions reduce the debt and are routed to their sub-ledgers.
     *
     * Returns an empty list when there is nothing to deduct, which the caller
     * reads as "post no entry" rather than posting an empty one.
     *
     * @param Collection<int, Deduction> $deductions
     * @return list<JournalLine>
     */
    public function buildDeductions(PayrollLine $line, Collection $deductions): array
    {
        if (! $line->deductionsTotal()->isPositive()) {
            return [];
        }

        $staff = $line->staffProfile;
        $staffId = (int) $staff->getKey();
        $branchId = $staff->branch_id;

        $lines = [
            JournalLine::debit(
                $this->accounts->systemId(SystemAccountCode::StaffPayable),
                $line->deductionsTotal(),
                $branchId,
                staffProfileId: $staffId,
            ),
        ];

        /*
         * Accumulated by ACCOUNT, then emitted one line per account.
         *
         * Grouping by deduction type was enough until an advance recovery
         * started crediting two accounts: the staff fund contribution and an
         * advance's charges both land on 7000, and emitting per type would put
         * two credit lines on the same account in one entry. That is not wrong
         * arithmetically, but it contradicts what this builder promises a
         * reader of the ledger — the total against an account, with the
         * itemisation left to `deductions`, which is where a payslip reads it.
         */
        $credits = [];

        foreach ($this->totalsByType($deductions) as $typeValue => $amount) {
            if (! $amount->isPositive()) {
                continue;
            }

            $type = DeductionType::from($typeValue);

            /*
             * A salary advance recovery is not all one thing, and crediting it
             * as though it were is wrong in a way the trial balance cannot see.
             *
             * Disbursement debited 7020 Staff Advance Receivable with the
             * PRINCIPAL. An instalment recovers principal plus the interest and
             * charge fee the advance was priced with — so crediting the whole
             * instalment to 7020 drives it below zero by exactly the charges,
             * asserting the company owes the employee money it does not, and
             * recognising the charges as income nowhere at all.
             *
             * The principal portion clears the receivable it created. The
             * charges credit 7000 Staff Fund, which is where §12 puts them: the
             * fund is an internal revolving one that "inazalisha faida ndani
             * yake" — generates its profit within itself — so what an advance
             * earns returns to the fund the staff collectively own rather than
             * to company income they have no claim on.
             *
             * Both sides still sum to the same instalment, so the entry
             * balances exactly as before; what changes is which account is
             * credited with which part.
             */
            $portions = $type === DeductionType::Advance
                ? $this->splitAdvanceRecovery($deductions, $amount)
                : [[$type->creditAccount(), $amount]];

            foreach ($portions as [$code, $portion]) {
                if (! $portion->isPositive()) {
                    continue;
                }

                $credits[$code->name] = isset($credits[$code->name])
                    ? [$code, $credits[$code->name][1]->add($portion)]
                    : [$code, $portion];
            }
        }

        foreach ($credits as [$code, $amount]) {
            $lines[] = JournalLine::credit(
                $this->accounts->systemId($code),
                $amount,
                $branchId,
                staffProfileId: $staffId,
            );
        }

        return $lines;
    }

    /**
     * Entry 3 — settle what remains owed.
     *
     * @return list<JournalLine>
     */
    public function buildPayment(PayrollLine $line, ChartOfAccount $bankAccount): array
    {
        $staff = $line->staffProfile;
        $staffId = (int) $staff->getKey();

        return [
            JournalLine::debit(
                $this->accounts->systemId(SystemAccountCode::StaffPayable),
                $line->netSalary(),
                $staff->branch_id,
                staffProfileId: $staffId,
            ),
            JournalLine::credit(
                (int) $bankAccount->getKey(),
                $line->netSalary(),
                $staff->branch_id,
                staffProfileId: $staffId,
            ),
        ];
    }

    /**
     * A staff advance leaving the fund — §11's staff advance disbursement.
     *
     * Dr Staff Advance Receivable · Cr Staff Fund. The Staff Fund is what the
     * employees have collectively contributed, so an advance is lent out of it
     * and recovered back into it by the payroll deduction — the two postings
     * are mirror images, which is what makes the fund's balance meaningful.
     *
     * @return list<JournalLine>
     */
    public function buildAdvanceDisbursement(Money $amount, int $staffProfileId, ?int $branchId): array
    {
        return [
            JournalLine::debit(
                $this->accounts->systemId(SystemAccountCode::StaffAdvanceReceivable),
                $amount,
                $branchId,
                staffProfileId: $staffProfileId,
            ),
            JournalLine::credit(
                $this->accounts->systemId(SystemAccountCode::StaffFund),
                $amount,
                $branchId,
                staffProfileId: $staffProfileId,
            ),
        ];
    }

    /**
     * A staff loan leaving the fund, on the same principle as an advance.
     *
     * @return list<JournalLine>
     */
    public function buildLoanDisbursement(Money $amount, int $staffProfileId, ?int $branchId): array
    {
        return [
            JournalLine::debit(
                $this->accounts->systemId(SystemAccountCode::StaffLoanReceivable),
                $amount,
                $branchId,
                staffProfileId: $staffProfileId,
            ),
            JournalLine::credit(
                $this->accounts->systemId(SystemAccountCode::StaffFund),
                $amount,
                $branchId,
                staffProfileId: $staffProfileId,
            ),
        ];
    }

    /**
     * Splits an advance instalment into the principal it clears and the
     * charges it earns.
     *
     * Principal first: an instalment pays down the receivable until it is
     * clear, and only what is left over is interest and fee. That ordering is
     * chosen because it keeps 7020 monotonically decreasing towards zero and
     * never negative, which is the property that made the bug visible.
     *
     * The split is computed from the advance's own cumulative recovery rather
     * than per-instalment, so rounding cannot accumulate: across the whole term
     * the principal portions sum to exactly the principal.
     *
     * Returned as pairs rather than a map keyed by account code: PHP coerces
     * a numeric string array key to an int, so '7020' would arrive back as
     * 7020 and no longer match the enum it came from.
     *
     * @param Collection<int, Deduction> $deductions
     * @return list<array{SystemAccountCode, Money}>
     */
    private function splitAdvanceRecovery(Collection $deductions, Money $instalment): array
    {
        $principalPortion = Money::zero();

        foreach ($deductions as $deduction) {
            if ($deduction->type !== DeductionType::Advance || $deduction->reference_id === null) {
                continue;
            }

            $advance = StaffAdvance::query()->find($deduction->reference_id);

            if ($advance === null) {
                continue;
            }

            /*
             * What this instalment moves the cumulative principal recovery by.
             * `amount_recovered` is still the figure from BEFORE this
             * deduction — RecoverStaffAdvanceAction runs after the posting.
             */
            $principal = $advance->amountMoney();
            $before = $advance->recoveredMoney()->min($principal);
            $after = $advance->recoveredMoney()->add($deduction->amountMoney())->min($principal);

            $principalPortion = $principalPortion->add($after->subtract($before));
        }

        return [
            [SystemAccountCode::StaffAdvanceReceivable, $principalPortion],
            [SystemAccountCode::StaffFund, $instalment->subtract($principalPortion)],
        ];
    }

    /**
     * @param Collection<int, Deduction> $deductions
     * @return array<string, Money>
     */
    private function totalsByType(Collection $deductions): array
    {
        $totals = [];

        foreach ($deductions as $deduction) {
            $key = $deduction->type->value;
            $totals[$key] = ($totals[$key] ?? Money::zero())->add($deduction->amountMoney());
        }

        return $totals;
    }
}
