<?php

declare(strict_types=1);

use App\Domain\Repayments\Services\PaymentAllocator;
use App\Models\LoanSchedule;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Verification of the client-confirmed allocation order.
 *
 *     1. Penalty  →  2. Advance  →  3. Principal  →  4. Interest
 *
 * Permanent, and implemented in exactly one place. Every scenario the client
 * listed is proved here against figures worked out by hand.
 *
 * The tests use unsaved LoanSchedule models: the allocator is pure, so it needs
 * no database, and keeping these out of one makes the arithmetic the only thing
 * under test.
 */
function orderInstallment(
    int $number,
    string $principalDue,
    string $interestDue,
    string $penaltyDue = '0.00',
    string $principalPaid = '0.00',
    string $interestPaid = '0.00',
    string $penaltyPaid = '0.00',
    ?string $dueDate = null,
): LoanSchedule {
    $s = new LoanSchedule([
        'installment_number' => $number,
        /*
         * Due in the PAST by default, and in installment order.
         *
         * Since the client's advance ruling an installment is only settled once
         * it has reached its due date, so a fixture dated in the future would
         * test nothing but the gate. These tests are about the ORDER money is
         * applied in, so every installment here is payable and the ordering is
         * the only variable. `$dueDate` is how the gate itself gets tested.
         */
        'due_date' => $dueDate ?? now()->subDays(400 - $number * 7)->toDateString(),
        'principal_due' => $principalDue,
        'interest_due' => $interestDue,
        'penalty_due' => $penaltyDue,
        'principal_paid' => $principalPaid,
        'interest_paid' => $interestPaid,
        'penalty_paid' => $penaltyPaid,
    ]);

    // The allocator keys its lines on the id; unsaved models need one.
    $s->id = $number;

    return $s;
}

/** @param list<LoanSchedule> $installments */
function orderSchedule(array $installments): Collection
{
    return new Collection($installments);
}

function orderAllocator(): PaymentAllocator
{
    return new PaymentAllocator;
}

/* ═══════════════════ THE ORDER, STEP BY STEP ═══════════════════ */

describe('allocation order', function (): void {
    it('pays a penalty first, and nothing else, when that is all the money covers', function (): void {
        // Owed: 5,000 penalty + 20,000 principal + 2,000 interest. Paid: 3,000.
        $result = orderAllocator()->allocate(
            Money::of('3000.00'),
            orderSchedule([orderInstallment(1, '20000.00', '2000.00', '5000.00')]),
        );

        expect($result->totalPenalty()->toDecimalString())->toBe('3000.00')
            ->and($result->totalPrincipal()->toDecimalString())->toBe('0.00')
            ->and($result->totalInterest()->toDecimalString())->toBe('0.00')
            ->and($result->unallocated->toDecimalString())->toBe('0.00');
    });

    it('clears the penalty exactly and stops', function (): void {
        $result = orderAllocator()->allocate(
            Money::of('5000.00'),
            orderSchedule([orderInstallment(1, '20000.00', '2000.00', '5000.00')]),
        );

        expect($result->totalPenalty()->toDecimalString())->toBe('5000.00')
            ->and($result->totalPrincipal()->toDecimalString())->toBe('0.00')
            ->and($result->totalInterest()->toDecimalString())->toBe('0.00');
    });

    it('spends an advance credit before any new cash — step 2', function (): void {
        /*
         * Owed 20,000 principal + 2,000 interest, with an 8,000 advance held.
         * A 1,000 cash payment arrives.
         *
         * Advance goes to principal first (8,000), then the cash (1,000) —
         * so principal takes 9,000 and interest is untouched.
         */
        $result = orderAllocator()->allocate(
            Money::of('1000.00'),
            orderSchedule([orderInstallment(1, '20000.00', '2000.00')]),
            Money::of('8000.00'),
        );

        expect($result->totalPrincipal()->toDecimalString())->toBe('9000.00')
            ->and($result->totalInterest()->toDecimalString())->toBe('0.00')
            ->and($result->advanceConsumed->toDecimalString())->toBe('8000.00')
            ->and($result->cashApplied()->toDecimalString())->toBe('1000.00');
    });

    it('covers penalty then advance then principal', function (): void {
        /*
         * Owed: 5,000 penalty + 20,000 principal + 3,000 interest.
         * Advance held: 6,000. Cash: 9,000.
         *
         *   Penalty   5,000 from cash      → cash 4,000 left
         *   Advance   6,000 to principal   → advance spent
         *   Principal 4,000 from cash      → principal 10,000 total
         *   Interest  nothing left
         */
        $result = orderAllocator()->allocate(
            Money::of('9000.00'),
            orderSchedule([orderInstallment(1, '20000.00', '3000.00', '5000.00')]),
            Money::of('6000.00'),
        );

        expect($result->totalPenalty()->toDecimalString())->toBe('5000.00')
            ->and($result->totalPrincipal()->toDecimalString())->toBe('10000.00')
            ->and($result->totalInterest()->toDecimalString())->toBe('0.00')
            ->and($result->advanceConsumed->toDecimalString())->toBe('6000.00');
    });

    it('reaches interest only once penalty and principal are clear', function (): void {
        /*
         * Owed: 1,000 penalty + 5,000 principal + 2,000 interest = 8,000.
         * Paid 8,000 — everything, in order, with interest last.
         */
        $result = orderAllocator()->allocate(
            Money::of('8000.00'),
            orderSchedule([orderInstallment(1, '5000.00', '2000.00', '1000.00')]),
        );

        expect($result->totalPenalty()->toDecimalString())->toBe('1000.00')
            ->and($result->totalPrincipal()->toDecimalString())->toBe('5000.00')
            ->and($result->totalInterest()->toDecimalString())->toBe('2000.00')
            ->and($result->unallocated->toDecimalString())->toBe('0.00');
    });

    it('puts principal BEFORE interest — the confirmed change', function (): void {
        /*
         * The regression guard for the rule that changed.
         *
         * Owed 10,000 principal + 10,000 interest, paid 10,000. Under the old
         * Penalty → Interest → Principal order this cleared the INTEREST.
         * Under the confirmed order it clears the PRINCIPAL.
         */
        $result = orderAllocator()->allocate(
            Money::of('10000.00'),
            orderSchedule([orderInstallment(1, '10000.00', '10000.00')]),
        );

        expect($result->totalPrincipal()->toDecimalString())->toBe('10000.00')
            ->and($result->totalInterest()->toDecimalString())->toBe('0.00');
    });
});

/* ═══════════════════ SCENARIOS THE CLIENT LISTED ═══════════════════ */

describe('payment scenarios', function (): void {
    it('handles a partial payment across the oldest installment first', function (): void {
        $result = orderAllocator()->allocate(
            Money::of('7000.00'),
            orderSchedule([
                orderInstallment(1, '5000.00', '1000.00'),
                orderInstallment(2, '5000.00', '1000.00'),
            ]),
        );

        // Installment 1 fully (5,000 + 1,000), then 1,000 onto #2's principal.
        expect($result->lines)->toHaveCount(2)
            ->and($result->lines[0]->principal->toDecimalString())->toBe('5000.00')
            ->and($result->lines[0]->interest->toDecimalString())->toBe('1000.00')
            ->and($result->lines[1]->principal->toDecimalString())->toBe('1000.00')
            ->and($result->lines[1]->interest->toDecimalString())->toBe('0.00');
    });

    it('banks an overpayment as an advance rather than losing it', function (): void {
        $result = orderAllocator()->allocate(
            Money::of('20000.00'),
            orderSchedule([orderInstallment(1, '5000.00', '1000.00')]),
        );

        expect($result->allocatedTotal()->toDecimalString())->toBe('6000.00')
            // §7: never silently kept in a schedule row.
            ->and($result->unallocated->toDecimalString())->toBe('14000.00');
    });

    it('consumes an advance across several installments as they fall due', function (): void {
        // 12,000 advance, no new cash, three installments of 5,000 + 1,000.
        $result = orderAllocator()->allocate(
            Money::zero(),
            orderSchedule([
                orderInstallment(1, '5000.00', '1000.00'),
                orderInstallment(2, '5000.00', '1000.00'),
                orderInstallment(3, '5000.00', '1000.00'),
            ]),
            Money::of('12000.00'),
        );

        // Principal first at each installment: 5,000 + 1,000 → 5,000 + 1,000 → 0.
        expect($result->advanceConsumed->toDecimalString())->toBe('12000.00')
            ->and($result->totalPrincipal()->toDecimalString())->toBe('10000.00')
            ->and($result->totalInterest()->toDecimalString())->toBe('2000.00')
            ->and($result->cashApplied()->toDecimalString())->toBe('0.00');
    });

    it('settles a loan in full and leaves nothing outstanding', function (): void {
        $installments = [
            orderInstallment(1, '5000.00', '500.00'),
            orderInstallment(2, '5000.00', '500.00'),
            orderInstallment(3, '5000.00', '500.00'),
        ];

        $result = orderAllocator()->allocate(Money::of('16500.00'), orderSchedule($installments));

        expect($result->allocatedTotal()->toDecimalString())->toBe('16500.00')
            ->and($result->unallocated->toDecimalString())->toBe('0.00')
            ->and($result->lines)->toHaveCount(3);

        // Applying every line must mark all three paid.
        foreach ($result->lines as $line) {
            $s = $installments[$line->scheduleId - 1];
            expect(orderAllocator()->applyToSchedule($s, $line)['status'])->toBe('paid');
        }
    });

    it('handles a late payment carrying a penalty on an older installment', function (): void {
        $result = orderAllocator()->allocate(
            Money::of('12000.00'),
            orderSchedule([
                orderInstallment(1, '5000.00', '1000.00', '2000.00'),
                orderInstallment(2, '5000.00', '1000.00'),
            ]),
        );

        // #1: penalty 2,000 → principal 5,000 → interest 1,000 = 8,000.
        // #2: principal 4,000 from what is left.
        expect($result->lines[0]->penalty->toDecimalString())->toBe('2000.00')
            ->and($result->lines[0]->principal->toDecimalString())->toBe('5000.00')
            ->and($result->lines[0]->interest->toDecimalString())->toBe('1000.00')
            ->and($result->lines[1]->principal->toDecimalString())->toBe('4000.00');
    });

    it('skips installments that are already settled', function (): void {
        $result = orderAllocator()->allocate(
            Money::of('3000.00'),
            orderSchedule([
                orderInstallment(1, '5000.00', '1000.00', principalPaid: '5000.00', interestPaid: '1000.00'),
                orderInstallment(2, '5000.00', '1000.00'),
            ]),
        );

        expect($result->lines)->toHaveCount(1)
            ->and($result->lines[0]->scheduleId)->toBe(2)
            ->and($result->lines[0]->principal->toDecimalString())->toBe('3000.00');
    });

    it('does nothing with a zero payment', function (): void {
        $result = orderAllocator()->allocate(Money::zero(), orderSchedule([orderInstallment(1, '5000.00', '1000.00')]));

        expect($result->lines)->toBeEmpty()
            ->and($result->allocatedTotal()->toDecimalString())->toBe('0.00')
            ->and($result->unallocated->toDecimalString())->toBe('0.00');
    });

    it('never lets paid exceed due', function (): void {
        $s = orderInstallment(1, '5000.00', '1000.00');
        $result = orderAllocator()->allocate(Money::of('999999.00'), orderSchedule([$s]));

        $applied = orderAllocator()->applyToSchedule($s, $result->lines[0]);

        expect($applied['principal_paid'])->toBe('5000.00')
            ->and($applied['interest_paid'])->toBe('1000.00')
            ->and($applied['status'])->toBe('paid');
    });
});

/* ═══════════════════ CONSERVATION — NO MONEY DISAPPEARS ═══════════════════ */

describe('conservation of money', function (): void {
    it('accounts for every shilling of every payment', function (): void {
        /*
         * The invariant that matters most: whatever goes in must come out as
         * either allocation or surplus. Exercised across a spread of amounts
         * against a fixed schedule.
         */
        foreach (['0.01', '1.00', '999.99', '7500.00', '18000.00', '50000.00', '1000000.00'] as $amount) {
            $result = orderAllocator()->allocate(
                Money::of($amount),
                orderSchedule([
                    orderInstallment(1, '5000.00', '1000.00', '500.00'),
                    orderInstallment(2, '5000.00', '1000.00'),
                    orderInstallment(3, '5000.00', '1000.00'),
                ]),
            );

            expect($result->cashApplied()->add($result->unallocated)->toDecimalString())
                ->toBe(Money::of($amount)->toDecimalString());
        }
    });

    it('accounts for cash and advance separately, never double-counting', function (): void {
        $result = orderAllocator()->allocate(
            Money::of('4000.00'),
            orderSchedule([orderInstallment(1, '10000.00', '2000.00')]),
            Money::of('3000.00'),
        );

        // 7,000 cleared, of which 3,000 came from the advance and 4,000 was cash.
        expect($result->allocatedTotal()->toDecimalString())->toBe('7000.00')
            ->and($result->advanceConsumed->toDecimalString())->toBe('3000.00')
            ->and($result->cashApplied()->toDecimalString())->toBe('4000.00')
            ->and($result->unallocated->toDecimalString())->toBe('0.00');
    });

    it('cannot spend more advance than is held', function (): void {
        $result = orderAllocator()->allocate(
            Money::zero(),
            orderSchedule([orderInstallment(1, '100000.00', '10000.00')]),
            Money::of('250.00'),
        );

        expect($result->advanceConsumed->toDecimalString())->toBe('250.00')
            ->and($result->allocatedTotal()->toDecimalString())->toBe('250.00');
    });
});
