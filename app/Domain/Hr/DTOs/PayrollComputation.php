<?php

declare(strict_types=1);

namespace App\Domain\Hr\DTOs;

use App\Domain\Hr\Enums\AllowanceType;
use App\Domain\Hr\Enums\DeductionType;
use App\Support\Money;

/**
 * One employee's pay for one period, computed and not yet written anywhere.
 *
 * Mirrors the frontend's `PayrollComputation`. It carries the itemised
 * allowances and deductions as well as their totals, because §2.9 stores both:
 * the totals sit on `payroll_lines` and the items in `allowances`/`deductions`,
 * and a line whose total disagreed with its items would be a payslip nobody
 * could explain.
 */
final readonly class PayrollComputation
{
    /**
     * @param list<array{type: AllowanceType, amount: Money}> $allowances
     * @param list<array{type: DeductionType, amount: Money}> $deductions
     */
    public function __construct(
        public Money $baseSalary,
        public Money $commissionAmount,
        public array $allowances,
        public Money $allowancesTotal,
        public array $deductions,
        public Money $deductionsTotal,
        public Money $netSalary,
    ) {}

    /**
     * What the employer owes before deductions — the credit side of the
     * recognition entry (§5: Dr Salary Expense · Cr Staff Payable).
     */
    public function grossPay(): Money
    {
        return $this->baseSalary->add($this->allowancesTotal)->add($this->commissionAmount);
    }

    /** The total of one deduction type, or zero if there is none. */
    public function deductionOf(DeductionType $type): Money
    {
        return Money::sum(
            array_map(
                static fn (array $d): Money => $d['amount'],
                array_filter($this->deductions, static fn (array $d): bool => $d['type'] === $type),
            ),
        );
    }

    /**
     * The `payroll_lines` row for this computation.
     *
     * @return array{
     *     base_salary: string,
     *     commission_amount: string,
     *     allowances_total: string,
     *     deductions_total: string,
     *     net_salary: string
     * }
     */
    public function toLineRow(): array
    {
        return [
            'base_salary' => $this->baseSalary->toDecimalString(),
            'commission_amount' => $this->commissionAmount->toDecimalString(),
            'allowances_total' => $this->allowancesTotal->toDecimalString(),
            'deductions_total' => $this->deductionsTotal->toDecimalString(),
            'net_salary' => $this->netSalary->toDecimalString(),
        ];
    }
}
