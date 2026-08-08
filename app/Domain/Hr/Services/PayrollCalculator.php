<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\DTOs\PayrollComputation;
use App\Domain\Hr\Enums\AllowanceType;
use App\Domain\Hr\Enums\DeductionType;
use App\Models\StaffAdvance;
use App\Models\StaffAllowance;
use App\Models\StaffDeduction;
use App\Models\StaffLoan;
use App\Models\StaffProfile;
use App\Support\Money;
use App\Support\Percentage;
use Illuminate\Support\Collection;

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
 *
 * ## What Module 7 changed
 *
 * Allowances used to be two class constants, so every branch employee drew the
 * same transport figure and `AllowanceType::Bonus` was unreachable — nothing in
 * the system could award one. They are now rows on `staff_allowances`, resolved
 * per employee per period, and the constants below survive only as the defaults
 * a new employee is enrolled on.
 *
 * Loan recovery used to be a flat `RECOVERY_PER_PERIOD`, uncapped and against a
 * loan that never closed. See `StaffLoanCalculator` for what that cost.
 */
final class PayrollCalculator
{
    /**
     * §11's Staff Fund contribution, withheld from every salary. The frontend
     * holds it as STAFF_FUND_CONTRIBUTION_PCT = 0.1.
     *
     * The HR document says "% ya salary (mfano 20%)" — an example, explicitly
     * labelled as one — so the frontend's figure is what both sides use.
     */
    public const string STAFF_FUND_CONTRIBUTION_RATE = '10.000';

    /**
     * The transport allowance a branch employee is enrolled on at registration.
     *
     * A default, not a rule: the value lands on a `staff_allowances` row that
     * HR can then change for that person. Nothing reads this constant at
     * payroll time — `RegisterStaffAction` and the seeder use it to create the
     * initial entitlement, and from then on the row is what counts.
     */
    public const string DEFAULT_TRANSPORT_ALLOWANCE = '50000.00';

    /** The airtime allowance everyone is enrolled on. Same standing as above. */
    public const string DEFAULT_AIRTIME_ALLOWANCE = '20000.00';

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

    public function __construct(
        private readonly SalaryAdvanceCalculator $advances,
        private readonly StaffLoanCalculator $loans,
    ) {}

    /**
     * Computes one payroll line.
     *
     * `$commissionAmount` arrives already resolved by the commission engine —
     * branch pool share plus any zone override. Payroll does not compute
     * commission; §11 makes them separate engines because commission depends
     * on a closed month and payroll does not.
     *
     * `$entitlements` and `$penalties` are the rows that apply to this period,
     * resolved by the caller so that generating a run for a hundred employees
     * is two queries rather than two hundred.
     *
     * @param Collection<int, StaffAllowance> $entitlements
     * @param Collection<int, StaffDeduction> $penalties
     */
    public function compute(
        StaffProfile $staff,
        Money $commissionAmount,
        Collection $entitlements,
        Collection $penalties,
        ?StaffLoan $outstandingLoan,
        ?StaffAdvance $outstandingAdvance,
    ): PayrollComputation {
        $allowances = $this->allowancesFrom($entitlements);
        $allowancesTotal = Money::sum(array_map(static fn (array $a): Money => $a['amount'], $allowances));

        $deductions = $this->deductionsFor($staff, $penalties, $outstandingLoan, $outstandingAdvance);
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
     *
     * Consulted at **registration** now rather than at payroll time — it
     * decides which entitlements a new employee is enrolled on. Once the rows
     * exist they are what payroll reads, so moving someone to head office is a
     * change to their allowances that somebody makes and can be seen, rather
     * than something payroll silently re-derives.
     */
    public function isBranchBased(?RoleName $role, ?int $branchId): bool
    {
        return $branchId !== null
            && $role !== null
            && ! in_array($role, self::NON_BRANCH_ROLES, true);
    }

    /**
     * The allowances a newly registered employee starts with.
     *
     * @return list<array{type: AllowanceType, amount: Money}>
     */
    public function defaultEntitlements(bool $isBranchBased): array
    {
        $entitlements = [];

        if ($isBranchBased) {
            $entitlements[] = [
                'type' => AllowanceType::Transport,
                'amount' => Money::of(self::DEFAULT_TRANSPORT_ALLOWANCE),
            ];
        }

        $entitlements[] = [
            'type' => AllowanceType::Airtime,
            'amount' => Money::of(self::DEFAULT_AIRTIME_ALLOWANCE),
        ];

        return $entitlements;
    }

    /**
     * The Staff Fund contribution for one employee.
     *
     * A percentage of BASE salary, not of gross — commission and allowances do
     * not increase what an employee contributes to the fund.
     */
    public function staffFundContribution(StaffProfile $staff): Money
    {
        return $staff->baseSalary()->percentage(Percentage::of(self::STAFF_FUND_CONTRIBUTION_RATE));
    }

    /**
     * Entitlement rows become payslip lines.
     *
     * Summed per type rather than emitted one row per entitlement: two bonuses
     * awarded in one month are two decisions, but they are one "Bonus" line on
     * the payslip, and `allowances` is what a payslip reads.
     *
     * @param Collection<int, StaffAllowance> $entitlements
     * @return list<array{type: AllowanceType, amount: Money}>
     */
    private function allowancesFrom(Collection $entitlements): array
    {
        $totals = [];

        foreach ($entitlements as $entitlement) {
            $key = $entitlement->type->value;
            $totals[$key] = ($totals[$key] ?? Money::zero())->add($entitlement->amountMoney());
        }

        $allowances = [];

        // Enum order, not insertion order, so a payslip lists its allowances
        // the same way every month regardless of when each was granted.
        foreach (AllowanceType::cases() as $type) {
            $amount = $totals[$type->value] ?? null;

            if ($amount !== null && $amount->isPositive()) {
                $allowances[] = ['type' => $type, 'amount' => $amount];
            }
        }

        return $allowances;
    }

    /**
     * @param Collection<int, StaffDeduction> $penalties
     * @return list<array{type: DeductionType, amount: Money}>
     */
    private function deductionsFor(
        StaffProfile $staff,
        Collection $penalties,
        ?StaffLoan $loan,
        ?StaffAdvance $advance,
    ): array {
        $deductions = [[
            'type' => DeductionType::StaffFund,
            'amount' => $this->staffFundContribution($staff),
        ]];

        if ($loan !== null) {
            /*
             * From the loan's own terms, not a constant: the principal spread
             * over the periods it was agreed for, capped at what is still owed.
             *
             * The cap is what makes the last instalment exact and what stops an
             * almost-cleared loan being over-recovered. The flat 50,000 this
             * replaced would happily deduct against a balance of nothing at
             * all — and did, because nothing ever closed a loan.
             */
            $recovery = $this->loans->recoveryFor($loan);

            if ($recovery->isPositive()) {
                $deductions[] = ['type' => DeductionType::Loan, 'amount' => $recovery];
            }
        }

        if ($advance !== null) {
            /*
             * From the advance's own terms, snapshotted from the band it was
             * priced by. See docs/modules/salary-advance.md.
             */
            $recovery = $this->advances->recoveryFor($advance);

            if ($recovery->isPositive()) {
                $deductions[] = ['type' => DeductionType::Advance, 'amount' => $recovery];
            }
        }

        /*
         * Penalties last, and summed. They are somebody's decision rather than
         * a derived figure, which is why they arrive as rows — see
         * StaffDeduction for why they are never recurring.
         */
        $penaltyTotal = Money::sum(
            $penalties->map(static fn (StaffDeduction $d): Money => $d->amountMoney())->all(),
        );

        if ($penaltyTotal->isPositive()) {
            $deductions[] = ['type' => DeductionType::Penalty, 'amount' => $penaltyTotal];
        }

        return $deductions;
    }
}
