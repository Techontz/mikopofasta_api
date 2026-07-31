<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\Enums\DeductionType;
use App\Domain\Hr\Services\CommissionCalculator;
use App\Domain\Hr\Services\PayrollCalculator;
use App\Domain\Hr\Services\SalaryAdvanceCalculator;
use App\Models\StaffAdvance;
use App\Models\StaffProfile;
use App\Support\Money;

/**
 * The payroll and commission engines in isolation — §11.
 *
 * Both calculators are pure, so these need no database: the staff are unsaved
 * models. That is exactly why the arithmetic lives in a service rather than in
 * the action that persists it — a payslip can be examined without a payroll
 * run, a ledger or a transaction in the way.
 */
function staff(string $baseSalary, bool $commissionEligible = true): StaffProfile
{
    $profile = new StaffProfile(['base_salary' => $baseSalary, 'commission_eligible' => $commissionEligible]);
    $profile->id = 1;

    return $profile;
}

function payroll(): PayrollCalculator
{
    return new PayrollCalculator(new SalaryAdvanceCalculator);
}

/**
 * An unsaved advance carrying real terms, for the recovery cases.
 *
 * The calculator now takes the advance rather than a flag, because the
 * instalment is derived from the advance's own terms — so a unit test has to
 * supply one. Unsaved is enough: nothing here touches the database.
 */
function advance(string $principal, string $interest = '0.00', string $fee = '0.00', int $periods = 1): StaffAdvance
{
    $advance = new StaffAdvance([
        'amount' => $principal,
        'interest_amount' => $interest,
        'charge_fee' => $fee,
        'recovery_periods' => $periods,
        'amount_recovered' => '0.00',
    ]);
    $advance->id = 1;

    return $advance;
}

function commission(): CommissionCalculator
{
    return new CommissionCalculator;
}

describe('payroll', function (): void {
    it('adds base, commission and allowances and subtracts deductions', function (): void {
        $result = payroll()->compute(
            staff: staff('800000.00'),
            commissionAmount: Money::of('12345.67'),
            isBranchBased: true,
            hasActiveLoan: false,
            outstandingAdvance: null,
        );

        // 800,000 + 12,345.67 + 70,000 allowances − 80,000 staff fund.
        expect($result->allowancesTotal->toDecimalString())->toBe('70000.00')
            ->and($result->deductionsTotal->toDecimalString())->toBe('80000.00')
            ->and($result->netSalary->toDecimalString())->toBe('802345.67');
    });

    it('gives branch staff a transport allowance and HQ staff none', function (): void {
        $branch = payroll()->compute(staff('800000.00'), Money::zero(), true, false, null);
        $hq = payroll()->compute(staff('800000.00'), Money::zero(), false, false, null);

        $types = fn ($c): array => array_map(
            static fn (array $a): string => $a['type']->value,
            $c->allowances,
        );

        expect($types($branch))->toBe(['transport', 'airtime'])
            // An HQ role has no commute to fund, so airtime only.
            ->and($types($hq))->toBe(['airtime'])
            ->and($branch->allowancesTotal->toDecimalString())->toBe('70000.00')
            ->and($hq->allowancesTotal->toDecimalString())->toBe('20000.00');
    });

    it('withholds the staff fund contribution from base salary alone', function (): void {
        $result = payroll()->compute(
            staff: staff('1000000.00'),
            // A large commission must not increase the fund contribution.
            commissionAmount: Money::of('500000.00'),
            isBranchBased: true,
            hasActiveLoan: false,
            outstandingAdvance: null,
        );

        expect($result->deductionOf(DeductionType::StaffFund)->toDecimalString())->toBe('100000.00');
    });

    it('recovers a loan at the flat rate and an advance on its own terms', function (): void {
        /*
         * 300,000 + 22,500 interest + 5,000 fee = 327,500 over two periods, so
         * 163,750 this month. The loan still recovers at the flat 50,000 — it
         * has no category to derive terms from, which is why the constant
         * survives for loans and not for advances.
         */
        $advance = advance('300000.00', '22500.00', '5000.00', 2);

        $both = payroll()->compute(staff('1000000.00'), Money::zero(), true, true, $advance);
        $loanOnly = payroll()->compute(staff('1000000.00'), Money::zero(), true, true, null);

        expect($both->deductionOf(DeductionType::Loan)->toDecimalString())->toBe('50000.00')
            ->and($both->deductionOf(DeductionType::Advance)->toDecimalString())->toBe('163750.00')
            ->and($both->deductionsTotal->toDecimalString())->toBe('313750.00')
            ->and($loanOnly->deductionsTotal->toDecimalString())->toBe('150000.00')
            ->and($loanOnly->deductionOf(DeductionType::Advance)->isZero())->toBeTrue();
    });

    it('recovers only what an advance still owes', function (): void {
        // Priced over three periods but almost clear: one period would take
        // 100,000, and only 1,000 is left.
        $advance = advance('300000.00', '0.00', '0.00', 3);
        $advance->amount_recovered = '299000.00';

        $result = payroll()->compute(staff('1000000.00'), Money::zero(), true, false, $advance);

        expect($result->deductionOf(DeductionType::Advance)->toDecimalString())->toBe('1000.00');
    });

    it('deducts nothing for an advance already settled', function (): void {
        $advance = advance('300000.00', '0.00', '0.00', 3);
        $advance->amount_recovered = '300000.00';

        $result = payroll()->compute(staff('1000000.00'), Money::zero(), true, false, $advance);

        // No deduction row at all, rather than one of zero.
        expect($result->deductionOf(DeductionType::Advance)->isZero())->toBeTrue();
    });

    it('keeps its itemisation consistent with its totals', function (): void {
        $result = payroll()->compute(staff('1234567.89'), Money::of('999.99'), true, true, advance('200000.00', '10000.00', '2000.00', 1));

        $allowances = Money::sum(array_map(static fn (array $a): Money => $a['amount'], $result->allowances));
        $deductions = Money::sum(array_map(static fn (array $d): Money => $d['amount'], $result->deductions));

        // A payslip whose total disagreed with its lines is one nobody can
        // explain to the person receiving it.
        expect($allowances->toDecimalString())->toBe($result->allowancesTotal->toDecimalString())
            ->and($deductions->toDecimalString())->toBe($result->deductionsTotal->toDecimalString())
            ->and($result->grossPay()->subtract($result->deductionsTotal)->toDecimalString())
            ->toBe($result->netSalary->toDecimalString());
    });

    it('rounds the fund contribution half-up on a fractional salary', function (): void {
        // 10% of 833,333.33 is 83,333.333 → 83,333.33.
        $result = payroll()->compute(staff('833333.33'), Money::zero(), false, false, null);

        expect($result->deductionOf(DeductionType::StaffFund)->toDecimalString())->toBe('83333.33');
    });

    it('can produce a negative net when deductions exceed pay', function (): void {
        /*
         * 100,000 base: 10,000 fund + 50,000 loan + 50,000 advance = 110,000,
         * against 100,000 + 20,000 allowances.
         *
         * The advance is sized to recover exactly 50,000 — a bare 50,000 over
         * one period — so this stays the same arithmetic it was written for
         * now that the instalment comes from the advance's own terms rather
         * than a flat figure.
         */
        $result = payroll()->compute(staff('100000.00'), Money::zero(), false, true, advance('50000.00'));

        expect($result->netSalary->toDecimalString())->toBe('10000.00');

        $worse = payroll()->compute(staff('50000.00'), Money::zero(), false, true, advance('50000.00'));

        // Surfaced rather than clamped: an employee whose recoveries outrun
        // their salary is a real situation, and hiding it would pay them money
        // the deduction schedule says they do not have.
        expect($worse->netSalary->isNegative())->toBeTrue();
    });

    it('treats HQ roles as non-branch regardless of a branch assignment', function (): void {
        expect(payroll()->isBranchBased(RoleName::Finance, 1))->toBeFalse()
            ->and(payroll()->isBranchBased(RoleName::Hr, 1))->toBeFalse()
            ->and(payroll()->isBranchBased(RoleName::Auditor, 1))->toBeFalse()
            ->and(payroll()->isBranchBased(RoleName::LoanOfficer, 1))->toBeTrue()
            ->and(payroll()->isBranchBased(RoleName::BranchManager, 1))->toBeTrue()
            // A branch role with no branch is not commuting to one either.
            ->and(payroll()->isBranchBased(RoleName::LoanOfficer, null))->toBeFalse();
    });

    it('is deterministic — identical input gives identical output', function (): void {
        $once = payroll()->compute(staff('987654.32'), Money::of('1111.11'), true, true, null);
        $twice = payroll()->compute(staff('987654.32'), Money::of('1111.11'), true, true, null);

        expect($once->toLineRow())->toBe($twice->toLineRow());
    });
});

describe('commission pools', function (): void {
    it('takes the HQ hold before the loss and the pool after both', function (): void {
        $pool = commission()->computePool(Money::of('1200000.00'), Money::zero());

        expect($pool->hqHoldAmount->toDecimalString())->toBe('24000.00')
            ->and($pool->distributableProfit->toDecimalString())->toBe('1176000.00')
            ->and($pool->poolAmount->toDecimalString())->toBe('235200.00')
            ->and($pool->poolPercentage->toDecimalString())->toBe('20.000')
            ->and($pool->distributable)->toBeTrue();
    });

    it('offsets a carried-forward loss', function (): void {
        $pool = commission()->computePool(Money::of('600000.00'), Money::of('100000.00'));

        // 600,000 − 100,000 − 12,000 hold = 488,000.
        expect($pool->distributableProfit->toDecimalString())->toBe('488000.00')
            ->and($pool->poolAmount->toDecimalString())->toBe('97600.00');
    });

    it('produces a zero pool for a branch still in loss', function (): void {
        // §11's hard rule: 150,000 profit against a 400,000 loss carried in.
        $pool = commission()->computePool(Money::of('150000.00'), Money::of('400000.00'));

        expect($pool->distributableProfit->isNegative())->toBeTrue()
            ->and($pool->distributable)->toBeFalse()
            // Zero, never negative — a negative pool would read as staff owing
            // the company money.
            ->and($pool->poolAmount->toDecimalString())->toBe('0.00');
    });

    it('produces a zero pool for a branch that broke exactly even', function (): void {
        $pool = commission()->computePool(Money::zero(), Money::zero());

        // "Distributable profit <= 0" — zero is not positive, so nothing is
        // shared.
        expect($pool->distributable)->toBeFalse()
            ->and($pool->poolAmount->toDecimalString())->toBe('0.00');
    });

    it('handles an outright loss', function (): void {
        $pool = commission()->computePool(Money::of('-250000.00'), Money::zero());

        expect($pool->distributable)->toBeFalse()
            ->and($pool->poolAmount->toDecimalString())->toBe('0.00')
            // The hold on a negative profit is negative, which keeps
            // distributable = profit − loss − hold arithmetically honest.
            ->and($pool->hqHoldAmount->toDecimalString())->toBe('-5000.00');
    });
});

describe('commission distribution', function (): void {
    it('weights each share by base-salary share', function (): void {
        $eligible = collect([
            tap(staff('1000000.00'), fn (StaffProfile $s) => $s->id = 1),
            tap(staff('500000.00'), fn (StaffProfile $s) => $s->id = 2),
            tap(staff('500000.00'), fn (StaffProfile $s) => $s->id = 3),
        ]);

        $shares = commission()->distributePool(Money::of('200000.00'), $eligible);

        // 2:1:1 on a 2,000,000 total base.
        expect($shares[0]['shareAmount']->toDecimalString())->toBe('100000.00')
            ->and($shares[1]['shareAmount']->toDecimalString())->toBe('50000.00')
            ->and($shares[2]['shareAmount']->toDecimalString())->toBe('50000.00');
    });

    it('distributes nothing from an empty pool', function (): void {
        $eligible = collect([tap(staff('1000000.00'), fn (StaffProfile $s) => $s->id = 1)]);

        expect(commission()->distributePool(Money::zero(), $eligible))->toBe([]);
    });

    it('distributes nothing when nobody is eligible', function (): void {
        expect(commission()->distributePool(Money::of('200000.00'), collect()))->toBe([]);
    });

    it('survives a total base of zero without dividing by it', function (): void {
        $eligible = collect([tap(staff('0.00'), fn (StaffProfile $s) => $s->id = 1)]);

        expect(commission()->distributePool(Money::of('200000.00'), $eligible))->toBe([]);
    });

    it('rounds each share independently, as the frontend does', function (): void {
        $eligible = collect([
            tap(staff('1000000.00'), fn (StaffProfile $s) => $s->id = 1),
            tap(staff('1000000.00'), fn (StaffProfile $s) => $s->id = 2),
            tap(staff('1000000.00'), fn (StaffProfile $s) => $s->id = 3),
        ]);

        $shares = commission()->distributePool(Money::of('100.00'), $eligible);
        $total = Money::sum(array_map(static fn (array $s): Money => $s['shareAmount'], $shares));

        /*
         * Each share is 33.33, summing to 99.99 rather than 100.00. That is
         * the contract's behaviour and it is harmless: a pool is a computed
         * entitlement, not cash in an account waiting to be emptied, and each
         * share is expensed on its own balanced entry. Pinned so that swapping
         * in Money::allocate() — which would force an exact sum — is a
         * deliberate change rather than a silent one.
         */
        expect($shares[0]['shareAmount']->toDecimalString())->toBe('33.33')
            ->and($total->toDecimalString())->toBe('99.99');
    });
});

describe('zone override', function (): void {
    it('takes 5% of the combined pools it oversees', function (): void {
        expect(commission()->zoneOverride(Money::of('300000.00'))->toDecimalString())->toBe('15000.00')
            ->and(commission()->zoneOverridePercentage()->toDecimalString())->toBe('5.000');
    });

    it('is nothing when the branches earned nothing', function (): void {
        expect(commission()->zoneOverride(Money::zero())->isZero())->toBeTrue();
    });
});
