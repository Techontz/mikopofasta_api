<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Exceptions\UnbalancedEntryException;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Domain\Loans\Enums\LoanStatus;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Loan;
use App\Support\Money;

describe('the posting engine', function (): void {
    beforeEach(function (): void {
        seedLedgerFoundation();
    });

    it('posts a balanced entry with its lines', function (): void {
        $actor = officerAt('Head Office', RoleName::Finance);
        $accounts = app(AccountResolver::class);

        $entry = app(LedgerService::class)->post(
            description: 'Test capital injection',
            sourceType: JournalSourceType::CapitalInjection,
            sourceId: null,
            lines: [
                JournalLine::debit((int) $accounts->defaultBankAccount()->getKey(), Money::of('1000.00')),
                JournalLine::credit($accounts->systemId(SystemAccountCode::Capital), Money::of('1000.00')),
            ],
            postedBy: $actor,
        );

        expect($entry->entry_number)->toStartWith('JE-')
            ->and($entry->lines)->toHaveCount(2)
            ->and($entry->isBalanced())->toBeTrue();
    });

    it('refuses an unbalanced entry', function (): void {
        $actor = officerAt('Head Office', RoleName::Finance);
        $accounts = app(AccountResolver::class);

        // Exact equality, not a tolerance — a single cent is a real error.
        expect(fn () => app(LedgerService::class)->post(
            description: 'Deliberately unbalanced',
            sourceType: JournalSourceType::CapitalInjection,
            sourceId: null,
            lines: [
                JournalLine::debit((int) $accounts->defaultBankAccount()->getKey(), Money::of('1000.00')),
                JournalLine::credit($accounts->systemId(SystemAccountCode::Capital), Money::of('999.99')),
            ],
            postedBy: $actor,
        ))->toThrow(UnbalancedEntryException::class);

        expect(JournalEntry::query()->where('description', 'Deliberately unbalanced')->exists())->toBeFalse();
    });

    it('refuses a single-sided entry', function (): void {
        $actor = officerAt('Head Office', RoleName::Finance);
        $accounts = app(AccountResolver::class);

        expect(fn () => app(LedgerService::class)->post(
            description: 'One-sided',
            sourceType: JournalSourceType::CapitalInjection,
            sourceId: null,
            lines: [JournalLine::debit($accounts->systemId(SystemAccountCode::Capital), Money::of('100.00'))],
            postedBy: $actor,
        ))->toThrow(UnbalancedEntryException::class);
    });

    it('refuses a zero-amount line at construction', function (): void {
        expect(fn () => JournalLine::debit(1, Money::zero()))
            ->toThrow(InvalidArgumentException::class);
    });

    it('refuses to post to an inactive account', function (): void {
        $actor = officerAt('Head Office', RoleName::Finance);
        $accounts = app(AccountResolver::class);

        $suspense = $accounts->system(SystemAccountCode::Suspense);
        $suspense->update(['status' => App\Enums\ActiveStatus::Inactive]);

        // Posting to a deactivated account would hide money somewhere no
        // report looks.
        expect(fn () => app(LedgerService::class)->post(
            description: 'To a dead account',
            sourceType: JournalSourceType::Repayment,
            sourceId: null,
            lines: [
                JournalLine::debit((int) $accounts->defaultBankAccount()->getKey(), Money::of('10.00')),
                JournalLine::credit((int) $suspense->getKey(), Money::of('10.00')),
            ],
            postedBy: $actor,
        ))->toThrow(UnbalancedEntryException::class);
    });

    it('keeps the balance cache in step with the lines', function (): void {
        $actor = officerAt('Head Office', RoleName::Finance);
        $accounts = app(AccountResolver::class);
        $capital = $accounts->system(SystemAccountCode::Capital);

        app(LedgerService::class)->post(
            description: 'Capital',
            sourceType: JournalSourceType::CapitalInjection,
            sourceId: null,
            lines: [
                JournalLine::debit((int) $accounts->defaultBankAccount()->getKey(), Money::of('5000.00')),
                JournalLine::credit((int) $capital->getKey(), Money::of('5000.00')),
            ],
            postedBy: $actor,
        );

        // Capital is equity — credit-normal — so a credit is a positive
        // balance.
        expect($capital->fresh(['balances'])->cachedBalance()->toDecimalString())->toBe('5000.00');
    });

    it('reports a branch-tagged account balance account-wide, not as zero', function (): void {
        seedLedgerActivity();

        /*
         * The regression this pins: `account_balances` holds one row per
         * (account, branch), and almost every posting carries a branch. Reading
         * only the branch-less row would report 0.00 for the loan book and
         * every income account — the whole accounts screen — while the trial
         * balance showed the real figures. The two must agree.
         *
         * Interest Income, not the Reserve. This test used the Reserve because
         * §5's real-time cut made it branch-tagged on every repayment. Decision
         * Register D1 moved that appropriation into the month-end close, where
         * the credit is deliberately company-wide, so a ledger with repayments
         * and no close now has no branch-tagged Reserve rows at all — and the
         * test failed on the fixture rather than on the behaviour.
         *
         * Interest Income is tagged with the earning branch on every collection,
         * which is exactly the shape the regression was about.
         */
        $account = App\Models\ChartOfAccount::query()->with('balances')
            ->where('code', SystemAccountCode::InterestIncome->value)->sole();

        $fromTrialBalance = collect(app(TrialBalanceBuilder::class)->build()['rows'])
            ->firstWhere('code', SystemAccountCode::InterestIncome->value)['balance'];

        expect($account->balances->count())->toBeGreaterThan(0)
            ->and($account->cachedBalance()->isPositive())->toBeTrue()
            ->and($account->cachedBalance()->toDecimalString())->toBe($fromTrialBalance);
    });

    it('serves that same balance through the accounts endpoint', function (): void {
        seedLedgerActivity();
        officerAt('Head Office', RoleName::Finance);

        $rows = collect($this->getJson('/api/v1/ledger/accounts')->assertOk()->json('data'))->keyBy('code');
        $trialBalance = collect(app(TrialBalanceBuilder::class)->build()['rows'])->keyBy('code');

        foreach ([SystemAccountCode::Reserve, SystemAccountCode::LoanReceivable, SystemAccountCode::InterestIncome] as $code) {
            expect($rows[$code->value]['balance'])->toBe($trialBalance[$code->value]['balance']);
        }
    });

    it('rebuilds every cached balance from the lines alone', function (): void {
        seedLedgerActivity();

        // The cache is derived, so wiping and rebuilding must be a no-op.
        // Keyed by account and branch rather than by id: the rebuild inserts
        // its own rows, so the ids legitimately differ.
        $snapshot = fn (): array => App\Models\AccountBalance::query()->get()
            ->mapWithKeys(fn ($b): array => [$b->account_id.':'.($b->branch_id ?? 'null') => $b->balance])
            ->sortKeys()
            ->all();

        $before = $snapshot();

        App\Models\AccountBalance::query()->delete();
        app(AccountResolver::class)->rebuildAllBalances();

        expect($snapshot())->toBe($before);
    });
});

describe('immutability', function (): void {
    beforeEach(function (): void {
        seedLedgerFoundation();
        seedLedgerActivity();
    });

    it('refuses to update or delete a journal entry', function (): void {
        $entry = JournalEntry::query()->firstOrFail();

        // §8: even a tinker session cannot quietly rewrite history.
        expect(fn () => $entry->update(['description' => 'tampered']))
            ->toThrow(App\Exceptions\ImmutableRecordException::class)
            ->and(fn () => $entry->delete())
            ->toThrow(App\Exceptions\ImmutableRecordException::class);

        expect($entry->fresh()->description)->not->toBe('tampered');
    });

    it('refuses to update or delete a journal line', function (): void {
        $line = JournalEntryLine::query()->firstOrFail();

        expect(fn () => $line->update(['debit_amount' => '1.00']))
            ->toThrow(App\Exceptions\ImmutableRecordException::class)
            ->and(fn () => $line->delete())
            ->toThrow(App\Exceptions\ImmutableRecordException::class);
    });
});

describe('trial balance', function (): void {
    beforeEach(function (): void {
        seedLedgerFoundation();
        seedLedgerActivity();
    });

    it('balances across the whole book', function (): void {
        $tb = app(TrialBalanceBuilder::class)->build();

        expect($tb['balanced'])->toBeTrue()
            ->and($tb['totalDebits'])->toBe($tb['totalCredits']);
    });

    it('proves every individual entry balances too', function (): void {
        $unbalanced = JournalEntry::query()->with('lines')->get()
            ->reject(fn (JournalEntry $e): bool => $e->isBalanced());

        expect($unbalanced)->toBeEmpty();
    });

    it('signs each balance by the account normal side', function (): void {
        $rows = collect(app(TrialBalanceBuilder::class)->build()['rows'])->keyBy('code');

        // Loan Receivable is an asset with a net debit — positive.
        $receivable = $rows[SystemAccountCode::LoanReceivable->value];
        expect(Money::of($receivable['balance'])->isNegative())->toBeFalse();

        // Capital is equity with a net credit — also positive, because it is
        // credit-normal. A naive Dr−Cr would show it negative.
        $capital = $rows[SystemAccountCode::Capital->value];
        expect(Money::of($capital['balance'])->isPositive())->toBeTrue();
    });

    it('still balances when scoped to one branch', function (): void {
        $branchId = Loan::query()->where('status', LoanStatus::Active)->value('branch_id');

        $tb = app(TrialBalanceBuilder::class)->build($branchId);

        expect($tb['balanced'])->toBeTrue();
    })->skip(fn (): bool => Loan::query()->where('status', LoanStatus::Active)->doesntExist(), 'No active loans seeded.');

    it('is recomputed from lines, not read from the cache', function (): void {
        // Corrupt the cache; the trial balance must be unaffected, because it
        // is evidence rather than an echo of the summary.
        App\Models\AccountBalance::query()->update(['balance' => '999999.99']);

        expect(app(TrialBalanceBuilder::class)->build()['balanced'])->toBeTrue();
    });
});

describe('reversal', function (): void {
    beforeEach(function (): void {
        seedLedgerFoundation();
        seedLedgerActivity();
    });

    it('posts a mirrored entry and leaves the original untouched', function (): void {
        officerAt('Head Office', RoleName::Admin);
        $entry = JournalEntry::query()->with('lines')->where('is_reversal', false)->firstOrFail();
        $originalLineCount = $entry->lines->count();

        $this->postJson("/api/v1/ledger/entries/{$entry->id}/reverse", ['reason' => 'Posted in error'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $finance = officerAt('Head Office', RoleName::Finance);
        $request = App\Models\ReversalRequest::query()->latest('id')->firstOrFail();

        $this->postJson("/api/v1/ledger/reversals/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $reversal = JournalEntry::query()->with('lines')->where('reversed_entry_id', $entry->getKey())->sole();

        expect($reversal->is_reversal)->toBeTrue()
            ->and($reversal->lines)->toHaveCount($originalLineCount)
            // The original is exactly as it was.
            ->and($entry->fresh(['lines'])->lines)->toHaveCount($originalLineCount);
    });

    it('nets the reversed entry to zero', function (): void {
        officerAt('Head Office', RoleName::Admin);
        $entry = JournalEntry::query()->with('lines')->where('is_reversal', false)->firstOrFail();

        $this->postJson("/api/v1/ledger/entries/{$entry->id}/reverse", ['reason' => 'Posted in error'])->assertCreated();

        officerAt('Head Office', RoleName::Finance);
        $request = App\Models\ReversalRequest::query()->latest('id')->firstOrFail();
        $this->postJson("/api/v1/ledger/reversals/{$request->id}/approve")->assertOk();

        $reversal = JournalEntry::query()->with('lines')->where('reversed_entry_id', $entry->getKey())->sole();

        // Original debits equal reversal credits, and vice versa.
        expect($entry->fresh(['lines'])->totalDebits()->toDecimalString())
            ->toBe($reversal->totalCredits()->toDecimalString())
            ->and($entry->fresh(['lines'])->totalCredits()->toDecimalString())
            ->toBe($reversal->totalDebits()->toDecimalString());
    });

    it('keeps the book balanced after a reversal', function (): void {
        officerAt('Head Office', RoleName::Admin);
        $entry = JournalEntry::query()->where('is_reversal', false)->firstOrFail();
        $this->postJson("/api/v1/ledger/entries/{$entry->id}/reverse", ['reason' => 'Posted in error'])->assertCreated();

        officerAt('Head Office', RoleName::Finance);
        $request = App\Models\ReversalRequest::query()->latest('id')->firstOrFail();
        $this->postJson("/api/v1/ledger/reversals/{$request->id}/approve")->assertOk();

        expect(app(TrialBalanceBuilder::class)->build()['balanced'])->toBeTrue();
    });

    it('separates requesting from approving', function (): void {
        $entry = JournalEntry::query()->where('is_reversal', false)->firstOrFail();

        // Admin holds `ledger.reverse.request` and NOT
        // `ledger.reverse.approve` — the frontend's role map is precise about
        // that, and it is the whole separation-of-duties control.
        officerAt('Head Office', RoleName::Admin);
        $this->postJson("/api/v1/ledger/entries/{$entry->id}/reverse", ['reason' => 'Posted in error'])->assertCreated();

        $request = App\Models\ReversalRequest::query()->latest('id')->firstOrFail();

        // §14: only Finance or Super Admin approve.
        $this->postJson("/api/v1/ledger/reversals/{$request->id}/approve")->assertForbidden();

        officerAt('Head Office', RoleName::Finance);
        $this->postJson("/api/v1/ledger/reversals/{$request->id}/approve")->assertOk();
    });

    it('refuses to let the requester approve their own reversal', function (): void {
        $finance = officerAt('Head Office', RoleName::Finance);
        $entry = JournalEntry::query()->where('is_reversal', false)->firstOrFail();

        // Finance holds BOTH grants, which is exactly the case the
        // self-approval guard exists for.
        $this->postJson("/api/v1/ledger/entries/{$entry->id}/reverse", ['reason' => 'Posted in error'])->assertCreated();

        $request = App\Models\ReversalRequest::query()->latest('id')->firstOrFail();

        $this->postJson("/api/v1/ledger/reversals/{$request->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'REVERSAL_NOT_PERMITTED');
    });

    it('refuses to reverse an entry twice, or to reverse a reversal', function (): void {
        officerAt('Head Office', RoleName::Admin);
        $entry = JournalEntry::query()->where('is_reversal', false)->firstOrFail();
        $this->postJson("/api/v1/ledger/entries/{$entry->id}/reverse", ['reason' => 'Posted in error'])->assertCreated();

        officerAt('Head Office', RoleName::Finance);
        $request = App\Models\ReversalRequest::query()->latest('id')->firstOrFail();
        $this->postJson("/api/v1/ledger/reversals/{$request->id}/approve")->assertOk();

        officerAt('Head Office', RoleName::Admin);

        $this->postJson("/api/v1/ledger/entries/{$entry->id}/reverse", ['reason' => 'Again'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ENTRY_ALREADY_REVERSED');

        $reversal = JournalEntry::query()->where('reversed_entry_id', $entry->getKey())->sole();

        $this->postJson("/api/v1/ledger/entries/{$reversal->id}/reverse", ['reason' => 'Chain'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'REVERSAL_NOT_PERMITTED');
    });

    it('records the reversal in the audit trail', function (): void {
        officerAt('Head Office', RoleName::Admin);
        $entry = JournalEntry::query()->where('is_reversal', false)->firstOrFail();
        $this->postJson("/api/v1/ledger/entries/{$entry->id}/reverse", ['reason' => 'Posted in error'])->assertCreated();

        officerAt('Head Office', RoleName::Finance);
        $request = App\Models\ReversalRequest::query()->latest('id')->firstOrFail();
        $this->postJson("/api/v1/ledger/reversals/{$request->id}/approve")->assertOk();

        expect(AuditLog::query()->where('action', AuditAction::ReversalRequested->value)->exists())->toBeTrue()
            ->and(AuditLog::query()->where('action', AuditAction::LedgerEntryReversed->value)->exists())->toBeTrue();
    });
});

describe('ledger endpoints', function (): void {
    beforeEach(function (): void {
        seedLedgerFoundation();
        seedLedgerActivity();
    });

    it('serves the chart of accounts with balances', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $response = $this->getJson('/api/v1/ledger/accounts')->assertOk();

        expect(collect($response->json('data'))->pluck('code'))->toContain('1000', '1200', '2000');
    });

    it('serves the trial balance with totals in the meta', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->getJson('/api/v1/ledger/trial-balance')
            ->assertOk()
            ->assertJsonPath('meta.balanced', true)
            ->assertJsonStructure(['data' => [['code', 'name', 'type', 'debitTotal', 'creditTotal', 'balance']], 'meta']);
    });

    it('serves a loan sub-ledger from the same lines', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $loan = Loan::query()->where('status', LoanStatus::Active)->firstOrFail();

        $response = $this->getJson("/api/v1/ledger/loans/{$loan->id}")->assertOk();

        // §2.7: a loan ledger is journal lines filtered, not a separate table.
        expect($response->json('meta.dimension'))->toBe('loans')
            ->and($response->json('data'))->not->toBeEmpty();
    });

    it('denies the ledger to a role without ledger.view', function (): void {
        // Loan Officer holds customers/loans grants only.
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->getJson('/api/v1/ledger/accounts')->assertForbidden();
        $this->getJson('/api/v1/ledger/trial-balance')->assertForbidden();
    });

    it('has no endpoint that posts an entry directly', function (): void {
        officerAt('Head Office', RoleName::Finance);

        // Entries are a consequence of a business event; LedgerService is the
        // only writer (§5).
        $this->postJson('/api/v1/ledger/entries', [])->assertStatus(405);
    });
});
