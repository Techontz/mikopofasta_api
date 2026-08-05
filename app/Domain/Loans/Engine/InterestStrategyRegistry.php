<?php

declare(strict_types=1);

namespace App\Domain\Loans\Engine;

use App\Domain\Loans\Exceptions\UnknownInterestFormulaException;

/**
 * Resolves an `interest_formulas.code` to the strategy that implements it.
 *
 * The seam between administrator-managed data and code. An administrator
 * creates a formula row; this finds the class that knows the arithmetic. If
 * there is no such class the loan is refused with a message naming what IS
 * available, rather than falling back to a default and silently pricing the
 * loan wrong.
 *
 * ## Adding a formula
 *
 * Write a class implementing InterestStrategy, register it in
 * LoanEngineServiceProvider, and insert the matching `interest_formulas` row.
 * Nothing else changes — not the engine, not the actions, not the controllers,
 * not the reports. That is the whole point of the indirection.
 *
 * ## Why refusing is right
 *
 * A registry that fell back to SIMPLE for an unrecognised code would turn a
 * configuration mistake into a mispriced loan that looks perfectly normal. The
 * failure has to be loud and it has to happen before any money is committed —
 * which it does, because the engine resolves the strategy while building the
 * schedule at approval.
 */
final class InterestStrategyRegistry
{
    /** @var array<string, InterestStrategy> keyed by upper-case code */
    private array $strategies = [];

    /**
     * @param iterable<InterestStrategy> $strategies
     */
    public function __construct(iterable $strategies = [])
    {
        foreach ($strategies as $strategy) {
            $this->register($strategy);
        }
    }

    public function register(InterestStrategy $strategy): void
    {
        $this->strategies[$this->normalise($strategy->code())] = $strategy;
    }

    /**
     * @throws UnknownInterestFormulaException
     */
    public function get(string $code): InterestStrategy
    {
        $key = $this->normalise($code);

        return $this->strategies[$key]
            ?? throw UnknownInterestFormulaException::for($code, $this->codes());
    }

    public function has(string $code): bool
    {
        return isset($this->strategies[$this->normalise($code)]);
    }

    /**
     * Every code with an implementation behind it.
     *
     * What the administration screens offer, and what the product validator
     * checks a formula against before a product can be saved — so an
     * unimplemented formula is caught while configuring rather than while
     * lending.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        $codes = array_keys($this->strategies);
        sort($codes);

        return $codes;
    }

    /**
     * @return array<string, string> code => description
     */
    public function describeAll(): array
    {
        $described = [];

        foreach ($this->strategies as $code => $strategy) {
            $described[$code] = $strategy->describe();
        }

        ksort($described);

        return $described;
    }

    /**
     * Codes are compared case-insensitively and without surrounding space.
     *
     * The code is typed by an administrator into a free-text column; "reducing"
     * and "REDUCING " are the same formula, and refusing the loan over the
     * difference would be pedantry rather than safety.
     */
    private function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }
}
