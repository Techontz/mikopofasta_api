<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\ClosePeriodAction;
use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Accounting\Exceptions\PeriodException;
use App\Domain\Accounting\Services\PeriodResultCalculator;
use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\PeriodBranchResult;
use App\Models\ReserveSetting;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoBankAccountSeeder;

/**
 * The month-end close — Decision Register D1.
 *
 * D1 moved the reserve off the repayment path: it is now "calculated from
 * realised profit during the accounting closing process". These tests pin both
 * halves of that — that profit is recognised, and that the reserve comes out of
 * it rather than out of each interest collection.
 */
beforeEach(function (): void {
    seedOrganization();
    /* The demo subclass: the same chart of accounts, plus the two bank
       accounts these ledger assertions post through. ChartOfAccountSeeder
       itself now creates no bank account — see DemoBankAccountSeeder. */
    $this->seed(DemoBankAccountSeeder::class);

    $this->actor = User::factory()->role(RoleName::Finance)->create();
    $this->branch = Branch::query()->firstOrFail();
    $this->accounts = app(AccountResolver::class);
    $this->ledger = app(LedgerService::class);
});

/**
 * Posts trading activity into a period: income earned, expense incurred.
 *
 * Deliberately a real balanced entry through LedgerService rather than a raw
 * insert — the close reads the ledger, so a test that wrote around it would be
 * proving nothing about the path production takes.
 */
function postTrading(string $period, string $income, string $expense, ?int $branchId): void
{
    $accounts = app(AccountResolver::class);
    $ledger = app(LedgerService::class);
    $actor = User::query()->firstOrFail();

    [, $end] = PeriodResultCalculator::bounds($period);

    $incomeMoney = Money::of($income);
    $expenseMoney = Money::of($expense);

    // Dr bank (asset) / Cr interest income — money earned.
    $ledger->post(
        'Test income '.$period,
        JournalSourceType::Repayment,
        null,
        [
            JournalLine::debit((int) $accounts->defaultBankAccount()->getKey(), $incomeMoney, $branchId),
            JournalLine::credit($accounts->systemId(SystemAccountCode::InterestIncome), $incomeMoney, $branchId),
        ],
        $actor,
        $end,
    );

    if ($expenseMoney->isPositive()) {
        // Dr salary expense / Cr bank — money spent.
        $ledger->post(
            'Test expense '.$period,
            JournalSourceType::Expense,
            null,
            [
                JournalLine::debit($accounts->systemId(SystemAccountCode::SalaryExpense), $expenseMoney, $branchId),
                JournalLine::credit((int) $accounts->defaultBankAccount()->getKey(), $expenseMoney, $branchId),
            ],
            $actor,
            $end,
        );
    }
}

function pastPeriod(int $monthsAgo = 1): string
{
    return CarbonImmutable::now()->subMonths($monthsAgo)->format('Y-m');
}

it('recognises profit and appropriates reserve from it', function (): void {
    ReserveSetting::singleton()->update(['percentage' => '10.000']);

    $period = pastPeriod();
    postTrading($period, '1000000.00', '400000.00', (int) $this->branch->getKey());

    $closed = app(ClosePeriodAction::class)->handle($period, $this->actor);

    // Profit = 1,000,000 − 400,000 = 600,000. Reserve = 10% of that.
    expect($closed->realised_profit)->toBe('600000.00')
        ->and($closed->reserve_appropriated)->toBe('60000.00')
        ->and($closed->status)->toBe(PeriodStatus::Closed);

    // The rate is stored, so a later change to ReserveSetting cannot
    // retroactively make a historical close look like bad arithmetic.
    expect($closed->reserve_percentage)->toBe('10.000');
});

it('leaves the ledger balanced after a close', function (): void {
    ReserveSetting::singleton()->update(['percentage' => '10.000']);

    $period = pastPeriod();
    postTrading($period, '1000000.00', '400000.00', (int) $this->branch->getKey());

    app(ClosePeriodAction::class)->handle($period, $this->actor);

    $trial = app(TrialBalanceBuilder::class)->build();

    expect($trial['balanced'])->toBeTrue();
});

it('moves the reserve into account 3000 and reduces Profit by the same amount', function (): void {
    ReserveSetting::singleton()->update(['percentage' => '10.000']);

    $period = pastPeriod();
    postTrading($period, '1000000.00', '400000.00', (int) $this->branch->getKey());

    app(ClosePeriodAction::class)->handle($period, $this->actor);

    $trial = collect(app(TrialBalanceBuilder::class)->build()['rows'])->keyBy('code');

    // Reserve is credit-normal and holds the appropriation.
    expect($trial[SystemAccountCode::Reserve->value]['balance'])->toBe('60000.00');

    // Profit carries the period's earnings LESS what went to reserve.
    expect($trial[SystemAccountCode::Profit->value]['balance'])->toBe('540000.00');
});

it('appropriates nothing from a branch that made a loss', function (): void {
    ReserveSetting::singleton()->update(['percentage' => '10.000']);

    $period = pastPeriod();
    postTrading($period, '100000.00', '400000.00', (int) $this->branch->getKey());

    $closed = app(ClosePeriodAction::class)->handle($period, $this->actor);

    // A negative profit has no earnings to protect capital from, and taking a
    // percentage of it would credit a reserve the branch never earned.
    expect($closed->realised_profit)->toBe('-300000.00')
        ->and($closed->reserve_appropriated)->toBe('0.00')
        ->and($closed->reserve_journal_entry_id)->toBeNull();
});

it('records the per-branch breakdown the commission engine reads', function (): void {
    ReserveSetting::singleton()->update(['percentage' => '10.000']);

    $period = pastPeriod();
    postTrading($period, '1000000.00', '400000.00', (int) $this->branch->getKey());

    app(ClosePeriodAction::class)->handle($period, $this->actor);

    $result = PeriodBranchResult::query()
        ->where('branch_id', $this->branch->getKey())
        ->firstOrFail();

    expect($result->realised_profit)->toBe('600000.00')
        ->and($result->reserve_appropriated)->toBe('60000.00');

    // The accessor §11 uses.
    expect(PeriodBranchResult::reserveFor((int) $this->branch->getKey(), $period)->toDecimalString())
        ->toBe('60000.00');
});

it('refuses to close the same period twice', function (): void {
    $period = pastPeriod();
    postTrading($period, '1000000.00', '400000.00', (int) $this->branch->getKey());

    app(ClosePeriodAction::class)->handle($period, $this->actor);

    // Closing twice would recognise the month's profit twice and appropriate
    // its reserve twice.
    app(ClosePeriodAction::class)->handle($period, $this->actor);
})->throws(PeriodException::class);

it('refuses to close a period that has not ended', function (): void {
    $period = CarbonImmutable::now()->format('Y-m');
    postTrading($period, '1000000.00', '0.00', (int) $this->branch->getKey());

    app(ClosePeriodAction::class)->handle($period, $this->actor);
})->throws(PeriodException::class);

it('refuses to close out of order while an earlier period is still open', function (): void {
    postTrading(pastPeriod(2), '500000.00', '0.00', (int) $this->branch->getKey());
    postTrading(pastPeriod(1), '500000.00', '0.00', (int) $this->branch->getKey());

    // The earlier period traded and was never closed, so the later one cannot
    // sweep income the earlier close has not yet taken.
    app(ClosePeriodAction::class)->handle(pastPeriod(1), $this->actor);
})->throws(PeriodException::class);

it('closes a period whose predecessor never traded', function (): void {
    $period = pastPeriod();
    postTrading($period, '500000.00', '0.00', (int) $this->branch->getKey());

    // A business that started trading this month cannot be asked to close a
    // month that never existed.
    $closed = app(ClosePeriodAction::class)->handle($period, $this->actor);

    expect($closed->status)->toBe(PeriodStatus::Closed);
});

it('refuses to close a period with no activity', function (): void {
    app(ClosePeriodAction::class)->handle(pastPeriod(), $this->actor);
})->throws(PeriodException::class);

it('reports the same profit before and after the close', function (): void {
    $period = pastPeriod();
    postTrading($period, '1000000.00', '400000.00', (int) $this->branch->getKey());

    $calculator = app(PeriodResultCalculator::class);
    $before = $calculator->forPeriod($period)->profit();

    app(ClosePeriodAction::class)->handle($period, $this->actor);

    /*
     * The close sweeps income and expense into Profit by posting to those very
     * accounts. If the calculator counted its own closing entries, a closed
     * period would report having earned exactly nothing — and every commission
     * pool derived from it would collapse to zero.
     */
    expect($calculator->forPeriod($period)->profit()->toDecimalString())
        ->toBe($before->toDecimalString());
});

it('lists closed periods newest first', function (): void {
    postTrading(pastPeriod(2), '500000.00', '0.00', (int) $this->branch->getKey());
    postTrading(pastPeriod(1), '700000.00', '0.00', (int) $this->branch->getKey());

    app(ClosePeriodAction::class)->handle(pastPeriod(2), $this->actor);
    app(ClosePeriodAction::class)->handle(pastPeriod(1), $this->actor);

    expect(AccountingPeriod::query()->orderByDesc('period')->pluck('period')->all())
        ->toBe([pastPeriod(1), pastPeriod(2)]);
});
