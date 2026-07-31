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
         * Grouped by type rather than one line per deduction: two deductions
         * of the same type credit the same account, and a reader of the ledger
         * wants the total against that account, not the itemisation. The
         * itemisation lives in `deductions`, which is where a payslip reads it
         * from.
         */
        foreach ($this->totalsByType($deductions) as $typeValue => $amount) {
            if (! $amount->isPositive()) {
                continue;
            }

            $lines[] = JournalLine::credit(
                $this->accounts->systemId(DeductionType::from($typeValue)->creditAccount()),
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
