<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Services;

use App\Models\CapitalContribution;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Who owns what share of the company.
 *
 * Ownership is a shareholder's cumulative contributed capital over everyone's
 * cumulative contributed capital — read from `capital_contributions` and
 * nothing else:
 *
 *     share % = shareholder's contributions ÷ all contributions × 100
 *
 * Deliberately NOT from ledger account 1000, a bank balance, profit, or the
 * loan book. Those move whenever the company spends, lends or earns, and none
 * of that changes who put the money in. A contribution that was removed (and
 * reversed in the ledger) is excluded, because it was a correction and not
 * capital.
 */
final class ShareholderOwnership
{
    /** Decimal places a percentage is reported to. */
    public const int SCALE = 2;

    /**
     * Every shareholder with a contribution, keyed by shareholder id.
     *
     * @return array<int, array{contributed: Money, percentage: string}>
     */
    public function all(): array
    {
        /** @var array<int, string> $totals */
        $totals = CapitalContribution::query()
            ->select('shareholder_id', DB::raw('SUM(amount) AS total'))
            ->groupBy('shareholder_id')
            ->pluck('total', 'shareholder_id')
            ->all();

        $contributed = array_map(static fn (string $t): Money => Money::of($t), $totals);
        $grand = Money::sum($contributed);

        return array_map(fn (Money $amount): array => [
            'contributed' => $amount,
            'percentage' => $this->percentage($amount, $grand),
        ], $contributed);
    }

    public function totalContributed(): Money
    {
        return Money::of((string) (CapitalContribution::query()->sum('amount') ?: '0'));
    }

    /**
     * `part ÷ whole × 100`, rounded half-up to SCALE places, as a decimal
     * string. Integer arithmetic on minor units, so 10,000,000 of 15,000,000
     * is exactly "66.67" and never 66.66666666666667.
     */
    public function percentage(Money $part, Money $whole): string
    {
        if ($whole->minor <= 0) {
            return number_format(0, self::SCALE, '.', '');
        }

        $scaled = 100 * (10 ** self::SCALE);
        $partMinor = $part->minor;
        $wholeMinor = $whole->minor;

        // Keep the product inside a PHP int. Dropping trailing digits from
        // both sides at once changes the ratio by far less than the rounding.
        while ($partMinor > intdiv(PHP_INT_MAX - $wholeMinor, $scaled)) {
            $partMinor = intdiv($partMinor, 10);
            $wholeMinor = intdiv($wholeMinor, 10);
        }

        $units = intdiv($partMinor * $scaled + intdiv($wholeMinor, 2), $wholeMinor);

        return sprintf('%d.%0'.self::SCALE.'d', intdiv($units, 10 ** self::SCALE), $units % (10 ** self::SCALE));
    }
}
