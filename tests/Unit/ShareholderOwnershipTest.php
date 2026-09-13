<?php

declare(strict_types=1);

use App\Domain\Treasury\Services\ShareholderOwnership;
use App\Support\Money;

/**
 * The ownership formula in isolation: part ÷ whole × 100, rounded half-up to
 * two places, in integer minor units — never through a float.
 */
it('computes a share of contributed capital', function (string $part, string $whole, string $expected): void {
    expect((new ShareholderOwnership)->percentage(Money::of($part), Money::of($whole)))->toBe($expected);
})->with([
    'two thirds' => ['10000000', '15000000', '66.67'],
    'one third' => ['5000000', '15000000', '33.33'],
    'everything' => ['7500000.50', '7500000.50', '100.00'],
    'one cent of a lot' => ['0.01', '1000000', '0.00'],
    'half-up' => ['1', '8', '12.50'],
    'no capital yet' => ['0', '0', '0.00'],
    'very large book' => ['9000000000000.00', '27000000000000.00', '33.33'],
]);
