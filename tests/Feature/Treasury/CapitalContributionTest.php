<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\CapitalContribution;
use App\Models\JournalEntry;
use App\Models\Shareholder;

/** Capital → Add Capitals. See docs/modules/capital.md. */
beforeEach(function (): void {
    seedLedgerFoundation();
});

function aShareholder(): Shareholder
{
    return Shareholder::query()->create([
        'full_name' => 'Mseti Ally',
        'phone' => '0777000111',
        'email' => 'mseti@example.com',
        'gender' => 'male',
        'date_of_birth' => '1992-12-12',
    ]);
}

function capitalPayload(Shareholder $s, array $overrides = []): array
{
    return array_merge([
        'shareholderId' => $s->id,
        'amount' => '6000000',
        'payMethod' => 'cash',
        'receiptNo' => null,
        'chequeNo' => null,
    ], $overrides);
}

describe('recording capital', function (): void {
    it('records a contribution and posts a balanced entry', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $shareholder = aShareholder();

        $this->postJson('/api/v1/capital-contributions', capitalPayload($shareholder))
            ->assertCreated()
            ->assertJsonPath('data.amount', '6000000.00')
            ->assertJsonPath('data.payMethodLabel', 'CASH');

        $contribution = CapitalContribution::query()->firstOrFail();
        expect($contribution->journal_entry_id)->not->toBeNull();

        $entry = JournalEntry::query()->with('lines')->findOrFail($contribution->journal_entry_id);
        expect($entry->lines)->toHaveCount(2);

        $debits = $entry->lines->sum(fn ($l) => (float) $l->debit_amount);
        $credits = $entry->lines->sum(fn ($l) => (float) $l->credit_amount);
        expect($debits)->toBe($credits)->and($debits)->toBe(6000000.0);
    });

    /** The load-bearing test: capital must land in account 1000. */
    it('credits the Capital account', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $shareholder = aShareholder();

        $resolver = app(AccountResolver::class);
        $capitalId = $resolver->systemId(SystemAccountCode::Capital);
        $before = (float) $resolver->system(SystemAccountCode::Capital)->load('balances')->cachedBalance()->toDecimalString();

        $this->postJson('/api/v1/capital-contributions', capitalPayload($shareholder))->assertCreated();

        $entry = JournalEntry::query()->with('lines')->latest('id')->firstOrFail();
        $creditLine = $entry->lines->firstWhere('account_id', $capitalId);

        expect($creditLine)->not->toBeNull()
            ->and((float) $creditLine->credit_amount)->toBe(6000000.0);

        $after = (float) $resolver->system(SystemAccountCode::Capital)->load('balances')->cachedBalance()->toDecimalString();
        expect($after - $before)->toBe(6000000.0);
    });

    it('debits the bank rather than the till when paid by cheque', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $shareholder = aShareholder();

        $this->postJson('/api/v1/capital-contributions', capitalPayload($shareholder, [
            'payMethod' => 'cheque', 'chequeNo' => 'CHQ-001',
        ]))->assertCreated();

        $entry = JournalEntry::query()->with('lines.account')->latest('id')->firstOrFail();
        $debit = $entry->lines->firstWhere(fn ($l) => (float) $l->debit_amount > 0);

        // Bank accounts are dynamic with no branch; a till carries one.
        expect($debit->account->branch_id)->toBeNull();
    });

    it('requires a cheque number only when the pay method is cheque', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $shareholder = aShareholder();

        $this->postJson('/api/v1/capital-contributions', capitalPayload($shareholder, ['payMethod' => 'cheque']))
            ->assertStatus(422)
            ->assertJsonPath('errors.chequeNo.0', 'A cheque number is required when the pay method is cheque.');

        $this->postJson('/api/v1/capital-contributions', capitalPayload($shareholder))->assertCreated();
    });

    it('refuses a zero or negative amount', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $shareholder = aShareholder();

        foreach (['0', '-100'] as $amount) {
            $this->postJson('/api/v1/capital-contributions', capitalPayload($shareholder, ['amount' => $amount]))
                ->assertStatus(422);
        }
    });
});

describe('listing', function (): void {
    it('returns both totals, which answer different questions', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $shareholder = aShareholder();

        foreach (['6000000', '30000000'] as $amount) {
            $this->postJson('/api/v1/capital-contributions', capitalPayload($shareholder, ['amount' => $amount]))
                ->assertCreated();
        }

        $response = $this->getJson('/api/v1/capital-contributions')->assertOk();

        expect($response->json('data'))->toHaveCount(2);
        expect($response->json('meta.shareholderCapital'))->toBe('36000000.00');
        // Both figures come from the same postings here, so they agree.
        expect((float) $response->json('meta.companyCapital'))->toBeGreaterThanOrEqual(36000000.0);
    });
});

describe('removing', function (): void {
    it('reverses the entry rather than deleting it', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $shareholder = aShareholder();

        $id = $this->postJson('/api/v1/capital-contributions', capitalPayload($shareholder))
            ->assertCreated()->json('data.id');

        $entriesBefore = JournalEntry::query()->count();

        $this->deleteJson("/api/v1/capital-contributions/{$id}")->assertOk();

        // §5: a posting is never deleted. A reversal is a NEW entry.
        expect(JournalEntry::query()->count())->toBe($entriesBefore + 1);
        expect(JournalEntry::query()->where('is_reversal', true)->exists())->toBeTrue();
        expect(CapitalContribution::query()->find($id))->toBeNull();
    });

    it('leaves the Capital account net zero after a reversal', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $shareholder = aShareholder();

        $resolver = app(AccountResolver::class);
        $before = (float) $resolver->system(SystemAccountCode::Capital)->load('balances')->cachedBalance()->toDecimalString();

        $id = $this->postJson('/api/v1/capital-contributions', capitalPayload($shareholder))->json('data.id');
        $this->deleteJson("/api/v1/capital-contributions/{$id}")->assertOk();

        $after = (float) $resolver->system(SystemAccountCode::Capital)->load('balances')->cachedBalance()->toDecimalString();
        expect($after)->toBe($before);
    });
});

describe('rbac', function (): void {
    it('requires treasury.manage to record and treasury.view to read', function (): void {
        $shareholder = aShareholder();

        // Admin holds treasury.view but not treasury.manage (§14).
        officerAt('Head Office', RoleName::Admin);
        $this->getJson('/api/v1/capital-contributions')->assertOk();
        $this->postJson('/api/v1/capital-contributions', capitalPayload($shareholder))->assertForbidden();

        officerAt('Kakonko', RoleName::LoanOfficer);
        $this->getJson('/api/v1/capital-contributions')->assertForbidden();
    });
});

describe('audit trail', function (): void {
    it('records who added and who removed capital', function (): void {
        $actor = officerAt('Head Office', RoleName::Finance);
        $shareholder = aShareholder();

        $id = $this->postJson('/api/v1/capital-contributions', capitalPayload($shareholder))->json('data.id');
        $this->deleteJson("/api/v1/capital-contributions/{$id}")->assertOk();

        foreach ([AuditAction::CapitalRecorded, AuditAction::CapitalDeleted] as $action) {
            expect(AuditLog::query()->where('action', $action->value)->where('user_id', $actor->id)->exists())
                ->toBeTrue("expected an audit entry for {$action->value}");
        }
    });
});
