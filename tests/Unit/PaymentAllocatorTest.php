<?php

declare(strict_types=1);

use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Domain\Repayments\Services\PaymentAllocator;
use App\Models\LoanSchedule;
use App\Support\Money;

/**
 * The allocation rule in isolation — Decision 2's Penalty → Interest →
 * Principal, oldest installment first.
 *
 * PaymentAllocator is pure, so these need no database: the installments are
 * unsaved models. That is the point of keeping the rule in a service rather
 * than in the action that persists it — the arithmetic can be examined without
 * a loan, a ledger or a transaction in the way.
 */

/**
 * @param array<string, string> $attributes
 */
function installment(int $number, string $principal, string $interest, string $penalty = '0.00', array $paid = []): LoanSchedule
{
    $schedule = new LoanSchedule([
        'installment_number' => $number,
        'due_date' => now()->addDays($number * 7)->toDateString(),
        'principal_due' => $principal,
        'interest_due' => $interest,
        'penalty_due' => $penalty,
        'principal_paid' => $paid['principal'] ?? '0.00',
        'interest_paid' => $paid['interest'] ?? '0.00',
        'penalty_paid' => $paid['penalty'] ?? '0.00',
        'status' => LoanScheduleStatus::Pending,
    ]);

    // Unsaved, but the allocator identifies lines by key, so each needs one.
    $schedule->id = $number;

    return $schedule;
}

/**
 * @param list<LoanSchedule> $schedules
 */
function allocate(string $amount, array $schedules): App\Domain\Repayments\DTOs\AllocationResult
{
    return (new PaymentAllocator)->allocate(Money::of($amount), collect($schedules));
}

it('takes penalty before interest and interest before principal', function (): void {
    $result = allocate('7000.00', [installment(1, '10000.00', '2000.00', '5000.00')]);

    $line = $result->lines[0];

    expect($line->penalty->toDecimalString())->toBe('5000.00')
        ->and($line->interest->toDecimalString())->toBe('2000.00')
        ->and($line->principal->toDecimalString())->toBe('0.00');
});

it('stops mid-priority when the money runs out', function (): void {
    // 3,000 covers the penalty and only half the interest.
    $result = allocate('3000.00', [installment(1, '10000.00', '2000.00', '2000.00')]);

    $line = $result->lines[0];

    expect($line->penalty->toDecimalString())->toBe('2000.00')
        ->and($line->interest->toDecimalString())->toBe('1000.00')
        ->and($line->principal->toDecimalString())->toBe('0.00')
        ->and($result->unallocated->isZero())->toBeTrue();
});

it('clears the oldest installment entirely before touching the next', function (): void {
    $result = allocate('13000.00', [
        installment(1, '10000.00', '2000.00'),
        installment(2, '10000.00', '2000.00'),
    ]);

    expect($result->lines)->toHaveCount(2)
        // The first installment is fully cleared: 12,000.
        ->and($result->lines[0]->total()->toDecimalString())->toBe('12000.00')
        // The remaining 1,000 goes to the second installment's interest, not
        // its principal.
        ->and($result->lines[1]->interest->toDecimalString())->toBe('1000.00')
        ->and($result->lines[1]->principal->toDecimalString())->toBe('0.00');
});

it('walks in installment order regardless of the order given', function (): void {
    $result = allocate('12000.00', [
        installment(3, '10000.00', '2000.00'),
        installment(1, '10000.00', '2000.00'),
        installment(2, '10000.00', '2000.00'),
    ]);

    expect($result->lines)->toHaveCount(1)
        ->and($result->lines[0]->scheduleId)->toBe(1);
});

it('accounts for what has already been paid', function (): void {
    $result = allocate('5000.00', [
        installment(1, '10000.00', '2000.00', paid: ['interest' => '2000.00', 'principal' => '8000.00']),
        installment(2, '10000.00', '2000.00'),
    ]);

    expect($result->lines[0]->principal->toDecimalString())->toBe('2000.00')
        ->and($result->lines[1]->interest->toDecimalString())->toBe('2000.00')
        ->and($result->lines[1]->principal->toDecimalString())->toBe('1000.00');
});

it('skips an installment that is already settled', function (): void {
    $result = allocate('5000.00', [
        installment(1, '10000.00', '2000.00', paid: ['interest' => '2000.00', 'principal' => '10000.00']),
        installment(2, '10000.00', '2000.00'),
    ]);

    expect($result->lines)->toHaveCount(1)
        ->and($result->lines[0]->scheduleId)->toBe(2);
});

it('leaves an overpayment unallocated rather than inflating a schedule', function (): void {
    $result = allocate('20000.00', [installment(1, '10000.00', '2000.00')]);

    // §7 routes the excess to Finance for a refund-or-apply decision; it is
    // never quietly absorbed by an installment row.
    expect($result->lines[0]->total()->toDecimalString())->toBe('12000.00')
        ->and($result->unallocated->toDecimalString())->toBe('8000.00');
});

it('allocates nothing when there is nothing outstanding', function (): void {
    $result = allocate('5000.00', [
        installment(1, '10000.00', '2000.00', paid: ['interest' => '2000.00', 'principal' => '10000.00']),
    ]);

    expect($result->lines)->toBeEmpty()
        ->and($result->unallocated->toDecimalString())->toBe('5000.00');
});

it('conserves the payment exactly — allocated plus unallocated equals the amount', function (): void {
    foreach (['0.01', '1234.56', '12000.00', '99999.99'] as $amount) {
        $result = allocate($amount, [
            installment(1, '10000.00', '2000.00', '333.33'),
            installment(2, '10000.00', '2000.00'),
        ]);

        expect($result->allocatedTotal()->add($result->unallocated)->toDecimalString())
            ->toBe(Money::of($amount)->toDecimalString());
    }
});

it('marks an installment paid only when all three components are settled', function (): void {
    $allocator = new PaymentAllocator;
    $schedule = installment(1, '10000.00', '2000.00', '1000.00');

    $partial = allocate('12999.99', [$schedule])->lines[0];
    expect($allocator->applyToSchedule($schedule, $partial)['status'])->toBe('partial');

    $full = allocate('13000.00', [installment(1, '10000.00', '2000.00', '1000.00')])->lines[0];
    expect($allocator->applyToSchedule($schedule, $full)['status'])->toBe('paid');
});
