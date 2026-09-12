<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\ClosePeriodAction;
use App\Domain\Accounting\Services\PeriodResultCalculator;
use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Enums\AuditAction;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DistributionSetting;
use App\Models\JournalEntry;
use App\Models\ReserveSetting;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoBankAccountSeeder;

/**
 * Profit distribution at month-end — ACCOUNT OVERVIEW §I.16 and §4.F.
 *
 *     "Profit → Dividend Account
 *      Split: 70% → Principal (Reinvestment) 30% → Shareholders"
 *
 * Two properties matter more than the arithmetic, and most of these tests are
 * about them: the distribution takes what the RESERVE LEFT (not gross profit),
 * and it is APPENDED to the close without disturbing the two postings that
 * were already there.
 */
beforeEach(function (): void {
    /* The same setup PeriodCloseTest uses, so both suites exercise one close
       against one chart of accounts rather than two arrangements of it. */
    seedOrganization();
    /* The demo subclass: the same chart of accounts, plus the two bank
       accounts these ledger assertions post through. ChartOfAccountSeeder
       itself now creates no bank account — see DemoBankAccountSeeder. */
    $this->seed(DemoBankAccountSeeder::class);

    $this->actor = User::factory()->role(RoleName::Finance)->create();
    $this->branch = Branch::query()->firstOrFail();

    ReserveSetting::singleton()->update(['percentage' => 10]);
});

/** The split as the documentation states it, before anybody changes it. */
it('is seeded at the documented 70/30 split', function (): void {
    $setting = DistributionSetting::singleton();

    expect((float) $setting->reinvestment_percentage)->toBe(70.0)
        ->and((float) $setting->dividend_percentage)->toBe(30.0);
});

describe('the distribution posting', function (): void {
    it('debits Profit and credits Principal and Dividend', function (): void {
        $period = closedPeriodWithProfit();

        $entry = JournalEntry::query()
            ->where('id', $period->distribution_journal_entry_id)
            ->with('lines.account')
            ->sole();

        expect($entry->source_type)->toBe(JournalSourceType::Dividend);

        $byCode = $entry->lines->groupBy(fn ($line): string => $line->account->code);

        expect($byCode->has(SystemAccountCode::Profit->value))->toBeTrue()
            ->and($byCode->has(SystemAccountCode::Principal->value))->toBeTrue()
            ->and($byCode->has(SystemAccountCode::Dividend->value))->toBeTrue();

        // Profit is debited; the two destinations are credited.
        expect($byCode[SystemAccountCode::Profit->value]->every(fn ($l): bool => (float) $l->debit_amount > 0))->toBeTrue()
            ->and($byCode[SystemAccountCode::Principal->value]->every(fn ($l): bool => (float) $l->credit_amount > 0))->toBeTrue()
            ->and($byCode[SystemAccountCode::Dividend->value]->every(fn ($l): bool => (float) $l->credit_amount > 0))->toBeTrue();
    });

    it('balances', function (): void {
        $period = closedPeriodWithProfit();

        $entry = JournalEntry::query()->with('lines')->find($period->distribution_journal_entry_id);

        expect((float) $entry->lines->sum('debit_amount'))
            ->toBe((float) $entry->lines->sum('credit_amount'));
    });

    it('splits what the reserve left, not the gross profit', function (): void {
        $period = closedPeriodWithProfit();

        $profit = (float) $period->realised_profit;
        $reserve = (float) $period->reserve_appropriated;
        $distributable = $profit - $reserve;

        $reinvested = (float) $period->reinvested_amount;
        $dividend = (float) $period->dividend_amount;

        /*
         * The document is explicit that reserve is taken first — "Inakatwa
         * kabla ya matumizi". Splitting gross profit would hand shareholders
         * money the reserve has already claimed.
         */
        expect($reinvested + $dividend)->toBeLessThanOrEqual($distributable + 0.01)
            ->and($reinvested + $dividend)->toBeGreaterThan($distributable - 1.0);

        // And it is demonstrably NOT a split of the gross figure.
        if ($reserve > 0) {
            expect($reinvested + $dividend)->toBeLessThan($profit);
        }
    });

    it('applies the configured rates to the distributable figure', function (): void {
        $period = closedPeriodWithProfit();

        $distributable = (float) $period->realised_profit - (float) $period->reserve_appropriated;

        expect((float) $period->reinvested_amount)
            ->toBeGreaterThan($distributable * 0.70 - 1.0)
            ->toBeLessThan($distributable * 0.70 + 1.0)
            ->and((float) $period->dividend_amount)
            ->toBeGreaterThan($distributable * 0.30 - 1.0)
            ->toBeLessThan($distributable * 0.30 + 1.0);
    });

    it('records the rates it used, so a later change cannot rewrite history', function (): void {
        $period = closedPeriodWithProfit();

        expect((float) $period->reinvestment_percentage)->toBe(70.0)
            ->and((float) $period->dividend_percentage)->toBe(30.0);

        // Change the policy; the closed period must not move.
        DistributionSetting::singleton()->update([
            'reinvestment_percentage' => 40,
            'dividend_percentage' => 60,
        ]);

        expect((float) $period->fresh()->reinvestment_percentage)->toBe(70.0);
    });

    it('honours a changed split on the next close', function (): void {
        DistributionSetting::singleton()->update([
            'reinvestment_percentage' => 50,
            'dividend_percentage' => 50,
        ]);

        $period = closedPeriodWithProfit();

        expect((float) $period->reinvestment_percentage)->toBe(50.0)
            ->and((float) $period->dividend_amount)
            ->toBeGreaterThan((float) $period->reinvested_amount - 1.0)
            ->toBeLessThan((float) $period->reinvested_amount + 1.0);
    });
});

describe('what the distribution must not disturb', function (): void {
    it('leaves profit recognition and reserve appropriation exactly as they were', function (): void {
        $period = closedPeriodWithProfit();

        // All three postings exist and are distinct entries.
        expect($period->profit_journal_entry_id)->not->toBeNull()
            ->and($period->reserve_journal_entry_id)->not->toBeNull()
            ->and($period->distribution_journal_entry_id)->not->toBeNull()
            ->and($period->profit_journal_entry_id)->not->toBe($period->reserve_journal_entry_id)
            ->and($period->reserve_journal_entry_id)->not->toBe($period->distribution_journal_entry_id);

        $profitEntry = JournalEntry::query()->find($period->profit_journal_entry_id);
        $reserveEntry = JournalEntry::query()->find($period->reserve_journal_entry_id);

        expect($profitEntry->source_type)->toBe(JournalSourceType::MonthEndProfit)
            ->and($reserveEntry->source_type)->toBe(JournalSourceType::ReserveAppropriation);
    });

    it('posts after the reserve, never before it', function (): void {
        $period = closedPeriodWithProfit();

        // Entry ids are monotonic, so this is the posting order.
        expect($period->distribution_journal_entry_id)
            ->toBeGreaterThan($period->reserve_journal_entry_id)
            ->and($period->reserve_journal_entry_id)
            ->toBeGreaterThan($period->profit_journal_entry_id);
    });

    it('keeps the ledger in balance overall', function (): void {
        closedPeriodWithProfit();

        $lines = App\Models\JournalEntryLine::query()->get();

        expect(round((float) $lines->sum('debit_amount'), 2))
            ->toBe(round((float) $lines->sum('credit_amount'), 2));
    });
});

/**
 * The figures a reader can check by hand, on the trial balance itself.
 *
 * The tests above assert relationships — "the split is of what the reserve
 * left", "the rates were applied". This one names the four numbers, so a change
 * that quietly alters where the money lands fails here with the amount printed
 * rather than as a broken ratio.
 *
 * 1,000,000 − 400,000 = 600,000 profit. Reserve at 10% takes 60,000, leaving
 * 540,000 distributable; 70/30 of that is 378,000 and 162,000, and their sum is
 * the whole of it — which is why Profit closes at zero.
 */
it('closes Profit to zero and lands the split on the trial balance', function (): void {
    closedPeriodWithProfit();

    $report = app(TrialBalanceBuilder::class)->build();
    $trial = collect($report['rows'])->keyBy('code');

    expect($report['balanced'])->toBeTrue()
        ->and($trial[SystemAccountCode::Reserve->value]['balance'])->toBe('60000.00')
        ->and($trial[SystemAccountCode::Principal->value]['balance'])->toBe('378000.00')
        ->and($trial[SystemAccountCode::Dividend->value]['balance'])->toBe('162000.00')
        // Reserve first, then the whole remainder appropriated — nothing left.
        ->and($trial[SystemAccountCode::Profit->value]['balance'])->toBe('0.00');
});

describe('the split setting endpoint', function (): void {
    it('reports the current split', function (): void {
        Laravel\Sanctum\Sanctum::actingAs(User::factory()->role(RoleName::Finance)->create(), ['*']);

        $this->getJson('/api/v1/distribution-setting')
            ->assertOk()
            ->assertJsonPath('data.reinvestmentPercentage', '70.00')
            ->assertJsonPath('data.dividendPercentage', '30.00');
    });

    it('refuses a split that does not total 100', function (): void {
        Laravel\Sanctum\Sanctum::actingAs(User::factory()->role(RoleName::SuperAdmin)->create(), ['*']);

        $this->putJson('/api/v1/distribution-setting', [
            'reinvestmentPercentage' => 80,
            'dividendPercentage' => 30,
        ])->assertStatus(422)->assertJsonValidationErrors(['dividendPercentage']);
    });

    it('accepts a split that totals 100 and audits it', function (): void {
        $actor = User::factory()->role(RoleName::SuperAdmin)->create();
        Laravel\Sanctum\Sanctum::actingAs($actor, ['*']);

        $this->putJson('/api/v1/distribution-setting', [
            'reinvestmentPercentage' => 60,
            'dividendPercentage' => 40,
        ])->assertOk()->assertJsonPath('data.reinvestmentPercentage', '60.00');

        $entry = AuditLog::query()
            ->where('action', AuditAction::DistributionSettingUpdated->value)
            ->sole();

        expect($entry->user_id)->toBe($actor->getKey())
            ->and($entry->before_json['reinvestment_percentage'])->toBe('70.00')
            ->and($entry->after_json['reinvestment_percentage'])->toBe('60.00');
    });

    it('refuses a role without treasury management', function (): void {
        Laravel\Sanctum\Sanctum::actingAs(User::factory()->role(RoleName::LoanOfficer)->create(), ['*']);

        $this->putJson('/api/v1/distribution-setting', [
            'reinvestmentPercentage' => 50,
            'dividendPercentage' => 50,
        ])->assertForbidden();

        expect((float) DistributionSetting::singleton()->reinvestment_percentage)->toBe(70.0);
    });
});

/**
 * Posts income and expense into a period, through the real ledger.
 *
 * A local copy rather than a shared helper: PeriodCloseTest has an identical
 * `postTrading()`, and Pest does not share function definitions across test
 * files. Lifting it into tests/Pest.php would mean editing a Phase 1 test to
 * remove its own copy, which this batch has no business doing.
 */
function postDistributionTrading(string $period, string $income, string $expense, ?int $branchId): void
{
    $accounts = app(AccountResolver::class);
    $ledger = app(LedgerService::class);
    $actor = User::query()->firstOrFail();

    [, $end] = PeriodResultCalculator::bounds($period);

    $incomeMoney = Money::of($income);
    $expenseMoney = Money::of($expense);

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

/**
 * Closes a period that made a profit, and returns it.
 *
 * Reuses PeriodCloseTest's `postTrading()` so the activity reaches the ledger
 * the way production does — the close reads the ledger, and a test that wrote
 * around it would prove nothing about the path that actually runs.
 *
 * 1,000,000 income against 400,000 expense leaves 600,000 profit; the reserve
 * is set to 10% in the setup, so 60,000 is appropriated and 540,000 is what
 * the distribution has to work from. Those figures are chosen to make the
 * "reserve first" assertions unambiguous rather than to be realistic.
 */
function closedPeriodWithProfit(): AccountingPeriod
{
    $period = CarbonImmutable::now()->subMonth()->format('Y-m');
    $branchId = Branch::query()->firstOrFail()->getKey();

    postDistributionTrading($period, '1000000', '400000', $branchId);

    app(ClosePeriodAction::class)->handle($period, User::query()->firstOrFail());

    return AccountingPeriod::query()->where('period', $period)->sole();
}
