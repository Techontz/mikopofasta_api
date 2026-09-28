<?php

namespace Tests\Unit;

use App\Services\Dividends\DividendMath;
use PHPUnit\Framework\TestCase;

class DividendMathTest extends TestCase
{
    public function test_amounts_convert_to_and_from_cents_exactly(): void
    {
        $this->assertSame(1000000001, DividendMath::toCents('10,000,000.01'));
        $this->assertSame(1000000001, DividendMath::toCents(10000000.01));
        $this->assertSame(-10050, DividendMath::toCents('-100.50'));
        $this->assertSame('10000000.01', DividendMath::fromCents(1000000001));
        $this->assertSame(3000, DividendMath::percentToBasis('30.00'));
        $this->assertSame(3010, DividendMath::percentToBasis(30.1));
    }

    public function test_split_sums_exactly_to_the_profit(): void
    {
        $this->assertSame(['pool' => 300000000, 'reinvest' => 700000001], DividendMath::split(1000000001, 3000));
        $this->assertSame(['pool' => 30000001, 'reinvest' => 70000002], DividendMath::split(100000003, 3000));
        $this->assertSame(['pool' => 0, 'reinvest' => 0], DividendMath::split(0, 3000));

        $huge = DividendMath::split(999999999999999, 3333);
        $this->assertSame(999999999999999, $huge['pool'] + $huge['reinvest']);
    }

    public function test_allocation_uses_the_largest_remainder_and_sums_to_the_pool(): void
    {
        $this->assertSame([1 => 150000000, 2 => 90000000, 3 => 60000000], DividendMath::allocate(300000000, [1 => 500, 2 => 300, 3 => 200], 1000));
        $this->assertSame([1 => 34, 2 => 33, 3 => 33], DividendMath::allocate(100, [1 => 1, 2 => 1, 3 => 1], 3));
        $this->assertSame([1 => 33, 2 => 34, 3 => 33], DividendMath::allocate(100, [1 => 333, 2 => 334, 3 => 333], 1000));

        $rows = DividendMath::allocate(987654321, [7 => 123456789, 8 => 987654321, 9 => 5], 1111111115);
        $this->assertSame(987654321, array_sum($rows));
    }
}
