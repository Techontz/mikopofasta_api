<?php

declare(strict_types=1);

use App\Domain\Loans\Engine\LoanEngine;
use App\Domain\Loans\Engine\LoanTerms;
use App\Models\LoanAdvance;
use App\Support\Money;
use App\Support\Percentage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Performance verification of the loan engine.
 *
 * Two questions, answered with measurements rather than assurances:
 *
 *   1. Does schedule generation scale? It is pure computation, so the risk is
 *      time and memory, not queries.
 *   2. Does anything around it issue a query per loan? That is the N+1 risk,
 *      and it is what actually breaks at 100,000 loans.
 *
 * The thresholds are deliberately loose. This is a regression guard against an
 * accidental O(n²) or a query in a loop, not a benchmark — a tight bound would
 * fail on a slower CI box and teach everyone to ignore it.
 */
function engine(): LoanEngine
{
    return app(LoanEngine::class);
}

function benchTerms(int $tenureDays = 360, int $frequencyDays = 30): LoanTerms
{
    return LoanTerms::create(
        principal: Money::of('1500000.00'),
        interestRate: Percentage::of('4.500'),
        tenureDays: $tenureDays,
        frequencyDays: $frequencyDays,
        startDate: CarbonImmutable::parse('2026-01-01'),
    );
}

describe('schedule generation at scale', function (): void {
    it('generates 10,000 twelve-installment schedules well inside a second per thousand', function (): void {
        $engine = engine();
        $terms = benchTerms();

        $start = hrtime(true);
        $installments = 0;

        for ($i = 0; $i < 10_000; $i++) {
            $installments += count($engine->scheduleFor($terms, 'REDUCING'));
        }

        $seconds = (hrtime(true) - $start) / 1e9;

        expect($installments)->toBe(120_000)
            // 10,000 loans is 1/10th of the stated target portfolio. Generous
            // bound: this runs in well under a second on a laptop.
            ->and($seconds)->toBeLessThan(10.0);
    });

    it('scales linearly rather than quadratically in installment count', function (): void {
        $engine = engine();

        $time = function (int $tenureDays) use ($engine): float {
            $terms = benchTerms($tenureDays, 1);
            $start = hrtime(true);

            for ($i = 0; $i < 200; $i++) {
                $engine->scheduleFor($terms, 'REDUCING');
            }

            return (hrtime(true) - $start) / 1e9;
        };

        $short = $time(180);
        $long = $time(1800);

        /*
         * Ten times the installments must not cost anything like a hundred
         * times the work. Compared with a wide margin because a laptop under
         * load is noisy; a quadratic regression would blow straight past it.
         */
        expect($long)->toBeLessThan(max($short, 0.001) * 40);
    });

    it('runs every formula at scale without exhausting memory', function (): void {
        $engine = engine();
        $terms = benchTerms(1080, 30);

        $before = memory_get_usage(true);

        foreach (['SIMPLE', 'FLAT', 'REDUCING', 'REDUCING_EMI'] as $formula) {
            for ($i = 0; $i < 2_000; $i++) {
                $engine->scheduleFor($terms, $formula);
            }
        }

        $growthMb = (memory_get_usage(true) - $before) / 1_048_576;

        // Schedules are discarded each iteration; nothing should accumulate.
        expect($growthMb)->toBeLessThan(32.0);
    });

    it('resolves a strategy in constant time however often it is asked', function (): void {
        $engine = engine();

        $start = hrtime(true);

        for ($i = 0; $i < 100_000; $i++) {
            $engine->supports('REDUCING');
        }

        expect((hrtime(true) - $start) / 1e9)->toBeLessThan(2.0);
    });
});

describe('no N+1 queries', function (): void {
    beforeEach(function (): void {
        seedLedgerFoundation();
    });

    it('generates a schedule without touching the database at all', function (): void {
        $engine = engine();
        $terms = benchTerms();

        DB::enableQueryLog();
        DB::flushQueryLog();

        for ($i = 0; $i < 500; $i++) {
            $engine->scheduleFor($terms, 'REDUCING');
        }

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Pure computation. Not "few queries" — none.
        expect($queries)->toBe(0);
    });

    it('answers advance balances for many loans in ONE query', function (): void {
        $loan = activeLoan();

        DB::enableQueryLog();
        DB::flushQueryLog();

        // The batch accessor exists precisely so a list screen does not ask
        // once per row.
        LoanAdvance::balancesFor(range(1, 500));

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($queries)->toBe(1)
            ->and($loan)->not->toBeNull();
    });

    it('keeps the query count flat as the number of loans grows', function (): void {
        activeLoan();

        $count = function (array $ids): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            LoanAdvance::balancesFor($ids);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        // Ten loans and a thousand loans must cost the same number of queries.
        expect($count(range(1, 10)))->toBe($count(range(1, 1_000)));
    });

    it('loads a loan schedule in a fixed number of queries whatever its length', function (): void {
        $loan = activeLoan();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $loan->fresh(['schedules', 'product.interestFormula', 'repaymentSchedule']);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One for the loan, one per eager-loaded relation — never one per
        // installment.
        expect($queries)->toBeLessThanOrEqual(6);
    });
});
