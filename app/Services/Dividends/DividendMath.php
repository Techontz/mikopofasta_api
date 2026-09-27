<?php

namespace App\Services\Dividends;

use InvalidArgumentException;

/**
 * Decimal-safe dividend arithmetic in integer cents (bcmath for the intermediate products, so large amounts × share
 * counts never overflow or lose precision).
 *
 *  - Pool     = profit × shareholder % (rounded half-up to the cent); Reinvestment = profit − pool, so the two always
 *    sum exactly to the profit.
 *  - Entitlement per shareholder = pool × shares ÷ total shares, floored to the cent; the cents left over are handed
 *    out one each by the largest remainder (ties: the order the rows were given in, i.e. shareholder id), so the
 *    entitlements always sum exactly to the pool.
 */
class DividendMath
{
    /**
     * "10000000.01" / 10000000.01 → 1000000001.
     */
    public static function toCents(string|int|float $amount): int
    {
        if (is_float($amount) || is_int($amount)) {
            return (int) round($amount * 100);
        }

        $amount = trim(str_replace([',', ' '], '', $amount));
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException("Not an amount: {$amount}");
        }

        $negative = str_starts_with($amount, '-');
        $absolute = ltrim($amount, '-+');
        $cents = bcadd(bcmul($absolute, '100', 3), '0.5', 0);

        return (int) ($negative ? '-'.$cents : $cents);
    }

    /**
     * 1000000001 → "10000000.01".
     */
    public static function fromCents(int $cents): string
    {
        return bcdiv((string) $cents, '100', 2);
    }

    public static function centsToFloat(int $cents): float
    {
        return (float) self::fromCents($cents);
    }

    /**
     * Percentage with two decimals as hundredths of a percent: "30.00" → 3000.
     */
    public static function percentToBasis(string|int|float $percent): int
    {
        return self::toCents(is_string($percent) ? $percent : (string) $percent);
    }

    /**
     * @return array{pool: int, reinvest: int}
     */
    public static function split(int $profitCents, int $shareholderPercentBasis): array
    {
        if ($profitCents <= 0) {
            return ['pool' => 0, 'reinvest' => max(0, $profitCents)];
        }

        // profit × basis ÷ 10,000, rounded half-up.
        $pool = (int) bcdiv(bcadd(bcmul((string) $profitCents, (string) $shareholderPercentBasis), '5000'), '10000', 0);

        return ['pool' => $pool, 'reinvest' => $profitCents - $pool];
    }

    /**
     * @param  array<int|string, int>  $shares  shares held keyed by shareholder id (in tie-break order)
     * @return array<int|string, int> entitlement cents keyed like $shares
     */
    public static function allocate(int $poolCents, array $shares, int $totalShares): array
    {
        if ($totalShares <= 0 || $poolCents <= 0) {
            return array_map(fn (): int => 0, $shares);
        }

        $amounts = [];
        $remainders = [];
        $order = 0;
        foreach ($shares as $key => $held) {
            $product = bcmul((string) $poolCents, (string) max(0, $held));
            $amounts[$key] = (int) bcdiv($product, (string) $totalShares, 0);
            $remainders[] = ['key' => $key, 'remainder' => (int) bcmod($product, (string) $totalShares), 'order' => $order++];
        }

        $leftover = $poolCents - array_sum($amounts);
        usort($remainders, fn (array $left, array $right): int => [$right['remainder'], $left['order']] <=> [$left['remainder'], $right['order']]);

        foreach ($remainders as $row) {
            if ($leftover <= 0) {
                break;
            }
            if ($shares[$row['key']] > 0) {
                $amounts[$row['key']]++;
                $leftover--;
            }
        }

        return $amounts;
    }
}
