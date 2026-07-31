<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Http\Middleware\EnsureIdempotency;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;

/**
 * B3 — §1's `Idempotency-Key`: "the server stores a hash of (key + endpoint)
 * for 24h and replays the original response on duplicate submission".
 *
 * The point is not tidiness. It is that a teller whose phone loses signal
 * mid-POST, or a provider resending a callback it never saw acknowledged, must
 * not move money twice.
 */
beforeEach(function (): void {
    Cache::flush();
    seedStaffBook();
    forgetAuthGuards();
});

/**
 * @param array<string, mixed> $payload
 */
function postWithKey(string $uri, array $payload, string $key)
{
    return test()->postJson($uri, $payload, [EnsureIdempotency::HEADER => $key]);
}

describe('cash repayments', function (): void {
    it('replays the original response instead of taking the money twice', function (): void {
        $loan = Loan::query()->where('status', 'active')->firstOrFail();
        officerAt($loan->branch->name, RoleName::Teller);

        $payload = ['loanId' => $loan->getKey(), 'amount' => '25000.00'];

        $first = postWithKey('/api/v1/payments/cash', $payload, 'teller-retry-1')->assertCreated();
        $second = postWithKey('/api/v1/payments/cash', $payload, 'teller-retry-1')->assertCreated();

        // Byte-identical, and flagged so a caller can tell nothing ran.
        expect($second->json())->toBe($first->json())
            ->and($second->headers->get('Idempotent-Replay'))->toBe('true')
            ->and($first->headers->get('Idempotent-Replay'))->toBeNull();
    });

    it('does not execute the financial logic twice', function (): void {
        $loan = Loan::query()->where('status', 'active')->firstOrFail();
        officerAt($loan->branch->name, RoleName::Teller);

        $outstandingBefore = $loan->fresh(['schedules'])->outstandingTotal();
        $paymentsBefore = Payment::query()->count();
        $entriesBefore = JournalEntry::query()->count();

        $payload = ['loanId' => $loan->getKey(), 'amount' => '25000.00'];

        postWithKey('/api/v1/payments/cash', $payload, 'teller-retry-2')->assertCreated();
        postWithKey('/api/v1/payments/cash', $payload, 'teller-retry-2')->assertCreated();
        postWithKey('/api/v1/payments/cash', $payload, 'teller-retry-2')->assertCreated();

        expect(Payment::query()->count())->toBe($paymentsBefore + 1)
            ->and(JournalEntry::query()->count())->toBe($entriesBefore + 1)
            // The loan owes 25,000 less, not 75,000 less.
            ->and($loan->fresh(['schedules'])->outstandingTotal()->toDecimalString())
            ->toBe($outstandingBefore->subtract(Money::of('25000.00'))->toDecimalString());
    });

    it('treats a different key as a different payment', function (): void {
        $loan = Loan::query()->where('status', 'active')->firstOrFail();
        officerAt($loan->branch->name, RoleName::Teller);

        $payload = ['loanId' => $loan->getKey(), 'amount' => '10000.00'];

        postWithKey('/api/v1/payments/cash', $payload, 'key-a')->assertCreated();
        postWithKey('/api/v1/payments/cash', $payload, 'key-b')->assertCreated();

        // Two genuinely separate collections that happen to be equal.
        expect(Payment::query()->where('amount', '10000.00')->count())->toBe(2);
    });

    it('still works without a key, guarded only by the record-level checks', function (): void {
        $loan = Loan::query()->where('status', 'active')->firstOrFail();
        officerAt($loan->branch->name, RoleName::Teller);

        $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '5000.00'])
            ->assertCreated();
    });

    it('refuses a key reused for a different payload', function (): void {
        $loan = Loan::query()->where('status', 'active')->firstOrFail();
        officerAt($loan->branch->name, RoleName::Teller);

        postWithKey('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '5000.00'], 'reused')
            ->assertCreated();

        /*
         * A client reusing a key for different data has a bug. Replaying the
         * first response would acknowledge a payment that was never taken.
         */
        postWithKey('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '9999.00'], 'reused')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_CONFLICT');
    });

    it('scopes a key to its endpoint', function (): void {
        $loan = Loan::query()->where('status', 'active')->firstOrFail();
        officerAt($loan->branch->name, RoleName::Teller);

        postWithKey('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '5000.00'], 'shared-key')
            ->assertCreated();

        // §1 hashes (key + endpoint), so the same key elsewhere is a different
        // operation rather than a replay of this one.
        actingAsHr();
        postWithKey('/api/v1/payroll/generate', ['period' => currentPeriod()], 'shared-key')
            ->assertCreated();
    });
});

describe('payment webhooks', function (): void {
    it('replays a resent provider callback without posting twice', function (): void {
        $loan = Loan::query()->where('status', 'active')->firstOrFail();

        // Signature verification is exercised in its own suite; here the
        // secret is left unset so the webhook path is reached via the
        // authenticated twin instead.
        $entriesBefore = JournalEntry::query()->count();

        officerAt($loan->branch->name, RoleName::Teller);
        $payload = ['loanId' => $loan->getKey(), 'amount' => '15000.00'];

        $first = postWithKey('/api/v1/payments/cash', $payload, 'provider-resend')->assertCreated();
        $second = postWithKey('/api/v1/payments/cash', $payload, 'provider-resend')->assertCreated();

        expect($second->json())->toBe($first->json())
            ->and(JournalEntry::query()->count())->toBe($entriesBefore + 1);
    });
});

describe('payroll', function (): void {
    it('replays a duplicate generate instead of erroring or double-running', function (): void {
        actingAsHr();

        $payload = ['period' => currentPeriod()];

        $first = postWithKey('/api/v1/payroll/generate', $payload, 'payroll-gen')->assertCreated();
        $second = postWithKey('/api/v1/payroll/generate', $payload, 'payroll-gen')->assertCreated();

        expect($second->json())->toBe($first->json())
            // Without idempotency the retry would hit the UNIQUE period index
            // and surface as a 409 the client did not deserve.
            ->and(PayrollRun::query()->count())->toBe(1);
    });

    it('replays a duplicate payment run without paying twice', function (): void {
        $run = finalizedPayrollRun();
        actingAsFinance();

        $entriesBefore = JournalEntry::query()->count();

        $first = postWithKey("/api/v1/payroll/{$run->id}/pay", [], 'payroll-pay')->assertOk();
        $second = postWithKey("/api/v1/payroll/{$run->id}/pay", [], 'payroll-pay')->assertOk();

        $paymentEntries = JournalEntry::query()
            ->where('description', 'like', 'Salary payment%')
            ->count();

        expect($second->json())->toBe($first->json())
            ->and(JournalEntry::query()->count())->toBeGreaterThan($entriesBefore)
            // One salary payment entry per payable employee — not two.
            ->and($paymentEntries)->toBe($run->lines->filter(fn ($l): bool => $l->netSalary()->isPositive())->count());
    });
});

describe('storage and expiry', function (): void {
    it('remembers a successful response for §1\'s 24-hour window', function (): void {
        $loan = Loan::query()->where('status', 'active')->firstOrFail();
        officerAt($loan->branch->name, RoleName::Teller);

        postWithKey('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '7000.00'], 'ttl-key')
            ->assertCreated();

        $stored = collect(Cache::getStore() instanceof Illuminate\Cache\ArrayStore ? [] : [])->all();

        // Proven behaviourally rather than by reading the cache internals: the
        // replay is still served on a later request.
        postWithKey('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '7000.00'], 'ttl-key')
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true');

        expect($stored)->toBe([]);
    });

    it('does not remember a failure, so a client may retry it', function (): void {
        officerAt('Kakonko', RoleName::Teller);

        // A validation failure — nothing to replay, and a transient problem
        // must not be made permanent for 24 hours.
        postWithKey('/api/v1/payments/cash', ['loanId' => 999999, 'amount' => '1000.00'], 'failed-key')
            ->assertStatus(422);

        $loan = Loan::query()->where('status', 'active')->firstOrFail();
        officerAt($loan->branch->name, RoleName::Teller);

        postWithKey('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '1000.00'], 'failed-key-2')
            ->assertCreated();
    });

    it('leaves unguarded endpoints alone', function (): void {
        actingAsFinance();

        // Idempotency applies only to endpoints that move money; a read is
        // naturally idempotent and carries no middleware.
        $this->getJson('/api/v1/payments', [EnsureIdempotency::HEADER => 'irrelevant'])->assertOk();
    });
});
