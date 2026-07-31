<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\DTOs\PayrollComputation;
use App\Domain\Hr\Enums\AllowanceType;
use App\Domain\Hr\Enums\DeductionType;
use App\Models\StaffAdvance;
use App\Models\StaffProfile;
use App\Support\Money;
use App\Support\Percentage;

/**
 * THE payroll engine — spec §11, mirroring the frontend's `computePayrollLine`.
 *
 * There is exactly one implementation, and the runtime action, the seeder and
 * the tests all call it. A second copy would be a second answer to "what is
 * this person paid this month", which is the kind of disagreement that shows
 * up as an unexplained variance in a salary expense account.
 *
 * Pure: it computes and returns, and writes nothing — no rows, no ledger. §11
 * splits payroll into HR generating and Finance finalizing precisely because
 * computing pay and posting it are two different acts by two different people,
 * and keeping the arithmetic free of side effects is what lets a draft run
 * exist at all.
 *
 * Every figure is `Money` (integer minor units). Nothing here is a float,
 * which is what makes a payslip reproducible rather than approximately right.
 */
final class PayrollCalculator
{
    /**
     * §11's Staff Fund contribution, withheld from every salary. The frontend
     * holds it as STAFF_FUND_CONTRIBUTION_PCT = 0.1.
     */
    public const string STAFF_FUND_CONTRIBUTION_RATE = '10.000';

    /**
     * The flat monthly recovery against an outstanding staff **loan** (the
     * frontend's RECOVERY_PER_PERIOD).
     *
     * Still flat, and still admittedly arbitrary: §11 says a loan is recovered
     * from payroll without giving a schedule, and the frontend picked a number.
     *
     * **Salary advances no longer use this.** They carry their own terms —
     * interest, charge fee and a recovery period count, snapshotted from the
     * category they were priced by — and SalaryAdvanceCalculator derives the
     * instalment from those and caps it at what is still owed. See
     * docs/modules/salary-advance.md. Staff loans have no equivalent category
     * yet, so they keep this figure until they get one.
     */
    public const string RECOVERY_PER_PERIOD = '50000.00';

    /** Drawn only by branch-based staff — HQ roles have no commute to fund. */
    public const string TRANSPORT_ALLOWANCE = '50000.00';

    /** Drawn by everyone. */
    public const string AIRTIME_ALLOWANCE = '20000.00';

    /**
     * Roles based at head office, which therefore draw no transport allowance.
     * Mirrors the frontend's NON_BRANCH_ROLES exactly.
     *
     * @var list<RoleName>
     */
    private const array NON_BRANCH_ROLES = [
        RoleName::SuperAdmin,
        RoleName::Admin,
        RoleName::Finance,
        RoleName::Hr,
        RoleName::Auditor,
    ];

    public function __construct(private readonly SalaryAdvanceCalculator $advances) {}

    /**
     * Computes one payroll line.
     *
     * `$commissionAmount` arrives already resolved by the commission engine —
     * branch pool share plus any zone override. Payroll does not compute
     * commission; §11 makes them separate engines because commission depends
     * on a closed month and payroll does not.
     */
    public function compute(
        StaffProfile $staff,
        Money $commissionAmount,
        bool $isBranchBased,
        bool $hasActiveLoan,
        ?StaffAdvance $outstandingAdvance,
    ): PayrollComputation {
        $allowances = $this->allowancesFor($isBranchBased);
        $allowancesTotal = Money::sum(array_map(static fn (array $a): Money => $a['amount'], $allowances));

        $deductions = $this->deductionsFor($staff, $hasActiveLoan, $outstandingAdvance);
        $deductionsTotal = Money::sum(array_map(static fn (array $d): Money => $d['amount'], $deductions));

        $base = $staff->baseSalary();

        return new PayrollComputation(
            baseSalary: $base,
            commissionAmount: $commissionAmount,
            allowances: $allowances,
            allowancesTotal: $allowancesTotal,
            deductions: $deductions,
            deductionsTotal: $deductionsTotal,
            netSalary: $base->add($commissionAmount)->add($allowancesTotal)->subtract($deductionsTotal),
        );
    }

    /**
     * Whether this employee draws the branch transport allowance.
     *
     * Both conditions matter: a role that is normally branch-based but has no
     * branch assigned (an unposted officer) is not commuting to one either.
     */
    public function isBranchBased(?RoleName $role, ?int $branchId): bool
    {
        return $branchId !== null
            && $role !== null
            && ! in_array($role, self::NON_BRANCH_ROLES, true);
    }

    /**
     * @return list<array{type: AllowanceType, amount: Money}>
     */
    private function allowancesFor(bool $isBranchBased): array
    {
        $allowances = [];

        if ($isBranchBased) {
            $allowances[] = ['type' => AllowanceType::Transport, 'amount' => Money::of(self::TRANSPORT_ALLOWANCE)];
        }

        $allowances[] = ['type' => AllowanceType::Airtime, 'amount' => Money::of(self::AIRTIME_ALLOWANCE)];

        return $allowances;
    }

    /**
     * @return list<array{type: DeductionType, amount: Money}>
     */
    private function deductionsFor(StaffProfile $staff, bool $hasActiveLoan, ?StaffAdvance $advance): array
    {
        // The Staff Fund contribution is a percentage of BASE salary, not of
        // gross — commission and allowances do not increase what an employee
        // contributes to the fund.
        $deductions = [[
            'type' => DeductionType::StaffFund,
            'amount' => $staff->baseSalary()->percentage(Percentage::of(self::STAFF_FUND_CONTRIBUTION_RATE)),
        ]];

        if ($hasActiveLoan) {
            $deductions[] = ['type' => DeductionType::Loan, 'amount' => Money::of(self::RECOVERY_PER_PERIOD)];
        }

        if ($advance !== null) {
            /*
             * From the advance's own terms, not a constant: the total repayable
             * spread over the periods it was agreed for, capped at what is
             * still owed.
             *
             * The cap is what makes the last instalment exact and what stops an
             * almost-cleared advance being over-recovered — the flat figure
             * this replaced would happily deduct 50,000 against a balance of
             * 3,000, and nothing closed the advance afterwards either.
             */
            $recovery = $this->advances->recoveryFor($advance);

            if ($recovery->isPositive()) {
                $deductions[] = ['type' => DeductionType::Advance, 'amount' => $recovery];
            }
        }

        return $deductions;
    }
}
