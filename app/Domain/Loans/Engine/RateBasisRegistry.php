<?php

declare(strict_types=1);

namespace App\Domain\Loans\Engine;

use App\Domain\Loans\Engine\Bases\AsConfiguredBasis;
use App\Domain\Loans\Exceptions\UnknownRateBasisException;

/**
 * Resolves an `interest_rate_bases.code` to the class that implements it.
 *
 * The same seam as InterestStrategyRegistry, for the same reason: what a rate
 * MEANS is administrator-managed data, and this finds the code behind the row.
 *
 * Unlike the formula registry it has a documented fallback — a product with no
 * basis configured is priced `AS_CONFIGURED`. That is not a guess. Nulls are
 * what every existing product row carries, and AS_CONFIGURED is by definition
 * the behaviour those products were configured under; refusing them would break
 * the entire live book to enforce a distinction the client has not yet drawn.
 *
 * An UNRECOGNISED code is still refused outright. A null means "nobody chose";
 * a code with no implementation means somebody chose something that does not
 * exist, and pricing a loan on it would be inventing arithmetic.
 */
final class RateBasisRegistry
{
    /** @var array<string, RateBasis> keyed by upper-case code */
    private array $bases = [];

    /**
     * @param iterable<RateBasis> $bases
     */
    public function __construct(iterable $bases = [])
    {
        foreach ($bases as $basis) {
            $this->register($basis);
        }
    }

    public function register(RateBasis $basis): void
    {
        $this->bases[$this->normalise($basis->code())] = $basis;
    }

    /**
     * @throws UnknownRateBasisException
     */
    public function get(?string $code): RateBasis
    {
        if ($code === null || trim($code) === '') {
            return $this->get(AsConfiguredBasis::CODE);
        }

        return $this->bases[$this->normalise($code)]
            ?? throw UnknownRateBasisException::for($code, $this->codes());
    }

    public function has(string $code): bool
    {
        return isset($this->bases[$this->normalise($code)]);
    }

    /** @return list<string> */
    public function codes(): array
    {
        $codes = array_keys($this->bases);
        sort($codes);

        return $codes;
    }

    /** @return array<string, string> code => description */
    public function describeAll(): array
    {
        $described = [];

        foreach ($this->bases as $code => $basis) {
            $described[$code] = $basis->describe();
        }

        ksort($described);

        return $described;
    }

    private function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }
}
