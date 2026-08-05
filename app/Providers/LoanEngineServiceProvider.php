<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Loans\Engine\Bases\AsConfiguredBasis;
use App\Domain\Loans\Engine\Bases\PerAnnumBasis;
use App\Domain\Loans\Engine\InterestStrategy;
use App\Domain\Loans\Engine\InterestStrategyRegistry;
use App\Domain\Loans\Engine\RateBasis;
use App\Domain\Loans\Engine\RateBasisRegistry;
use App\Domain\Loans\Engine\Strategies\FlatRateStrategy;
use App\Domain\Loans\Engine\Strategies\ReducingBalanceAnnuityStrategy;
use App\Domain\Loans\Engine\Strategies\ReducingBalanceStrategy;
use App\Domain\Loans\Engine\Strategies\SimpleInterestStrategy;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the interest strategies into the registry.
 *
 * **This list is the only place a new formula is mentioned.** Adding Rule of
 * 78, a balloon loan or Murabaha means writing the class, adding one line here,
 * and inserting the `interest_formulas` row. No controller, action, report or
 * component changes, because none of them knows which strategies exist.
 *
 * Registered as singletons: a strategy holds no state and constructing one per
 * installment of a hundred-thousand-loan portfolio would be pure waste.
 */
final class LoanEngineServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Every implemented formula.
     *
     * @var list<class-string<InterestStrategy>>
     */
    private const array STRATEGIES = [
        SimpleInterestStrategy::class,
        FlatRateStrategy::class,
        ReducingBalanceStrategy::class,
        ReducingBalanceAnnuityStrategy::class,
    ];

    /**
     * Every implemented rate basis — P2's two answers.
     *
     * Both are registered in code; which of them an administrator may actually
     * SELECT is decided by `interest_rate_bases.is_active`, and PER_ANNUM is
     * seeded inactive. That separation is deliberate: the client asked for the
     * option to be enable-able without changing the engine, which means the
     * code has to be here and the decision has to be data.
     *
     * @var list<class-string<RateBasis>>
     */
    private const array RATE_BASES = [
        AsConfiguredBasis::class,
        PerAnnumBasis::class,
    ];

    public function register(): void
    {
        foreach ([...self::STRATEGIES, ...self::RATE_BASES] as $class) {
            $this->app->singleton($class);
        }

        $this->app->singleton(InterestStrategyRegistry::class, function ($app): InterestStrategyRegistry {
            return new InterestStrategyRegistry(
                array_map(static fn (string $class): InterestStrategy => $app->make($class), self::STRATEGIES),
            );
        });

        $this->app->singleton(RateBasisRegistry::class, function ($app): RateBasisRegistry {
            return new RateBasisRegistry(
                array_map(static fn (string $class): RateBasis => $app->make($class), self::RATE_BASES),
            );
        });
    }

    /** @return list<string> */
    public function provides(): array
    {
        return [
            InterestStrategyRegistry::class,
            RateBasisRegistry::class,
            ...self::STRATEGIES,
            ...self::RATE_BASES,
        ];
    }
}
