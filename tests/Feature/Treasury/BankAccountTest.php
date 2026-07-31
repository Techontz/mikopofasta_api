<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\Enums\AccountType;
use App\Enums\ActiveStatus;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;

/** Bank → Register Account and Account Balance. */
beforeEach(function (): void {
    seedLedgerFoundation();
});

/** @param array<string, mixed> $overrides */
function bankAccountPayload(array $overrides = []): array
{
    return array_merge([
        'bankName' => 'CRDB Bank',
        'accountName' => 'Mikopofasta Operations',
        'accountNumber' => '0150999888777',
        'currency' => 'TZS',
        'openingBalance' => 0,
        'status' => 'active',
        'description' => 'Day-to-day operating account.',
    ], $overrides);
}

describe('registering', function (): void {
    it('mints an 8xxx chart account the bank account owns', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/bank-accounts', bankAccountPayload())
            ->assertCreated()
            ->assertJsonPath('data.bankName', 'CRDB Bank');

        $account = BankAccount::query()->where('account_number', '0150999888777')->firstOrFail();
        $chart = ChartOfAccount::query()->findOrFail($account->chart_account_id);

        expect($chart->type)->toBe(AccountType::Asset);
        expect($chart->is_system)->toBeFalse();
        expect($chart->name)->toBe('CRDB Bank — Mikopofasta Operations');

        // Continues the seeder's 8000/8010 scheme rather than inventing a
        // second one for accounts created through the UI.
        expect((int) $chart->code)->toBeGreaterThanOrEqual(8020);
    });

    it('posts an opening balance through the ledger rather than storing a number', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $entriesBefore = JournalEntry::query()->count();

        $this->postJson('/api/v1/bank-accounts', bankAccountPayload(['openingBalance' => 500000]))
            ->assertCreated();

        // §5: every shilling passes through the ledger. Money that exists when
        // an account is opened came from the owners, so it credits Capital.
        expect(JournalEntry::query()->count())->toBe($entriesBefore + 1);

        $entry = JournalEntry::query()->with('lines')->latest('id')->firstOrFail();
        expect($entry->lines)->toHaveCount(2);
        expect($entry->lines->sum(fn ($l) => (float) $l->debit_amount))->toBe(500000.0);

        $account = BankAccount::query()->where('account_number', '0150999888777')
            ->with('chartAccount.balances')->firstOrFail();
        expect((float) $account->currentBalance()->toDecimalString())->toBe(500000.0);
    });

    it('posts nothing when the opening balance is zero', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $entriesBefore = JournalEntry::query()->count();

        $this->postJson('/api/v1/bank-accounts', bankAccountPayload())->assertCreated();

        expect(JournalEntry::query()->count())->toBe($entriesBefore);
    });

    it('refuses a duplicate account number', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/bank-accounts', bankAccountPayload())->assertCreated();

        $this->postJson('/api/v1/bank-accounts', bankAccountPayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.accountNumber.0', 'That account number is already registered.');
    });

    it('refuses an account number with letters in it', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/bank-accounts', bankAccountPayload(['accountNumber' => 'ACC-12345']))
            ->assertStatus(422)
            ->assertJsonPath('errors.accountNumber.0', 'Digits and dashes only.');
    });

    it('refuses a negative opening balance', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/bank-accounts', bankAccountPayload(['openingBalance' => -1]))
            ->assertStatus(422)
            ->assertJsonPath('errors.openingBalance.0', 'An opening balance cannot be negative.');
    });

    it('records who registered it', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/bank-accounts', bankAccountPayload())->assertCreated();

        expect(AuditLog::query()->where('action', AuditAction::BankAccountRegistered->value)->exists())->toBeTrue();
    });
});

describe('editing', function (): void {
    it('renames the chart account with the bank account', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/bank-accounts', bankAccountPayload())->assertCreated();
        $account = BankAccount::query()->where('account_number', '0150999888777')->firstOrFail();

        $this->putJson("/api/v1/bank-accounts/{$account->id}", bankAccountPayload([
            'accountName' => 'Mikopofasta Collections',
        ]))->assertOk();

        expect($account->fresh()->chartAccount->name)->toBe('CRDB Bank — Mikopofasta Collections');
    });

    it('takes the chart account out of service when deactivated', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/bank-accounts', bankAccountPayload())->assertCreated();
        $account = BankAccount::query()->where('account_number', '0150999888777')->firstOrFail();

        $this->putJson("/api/v1/bank-accounts/{$account->id}", bankAccountPayload(['status' => 'inactive']))
            ->assertOk();

        // LedgerService refuses an inactive account, so "inactive" means the
        // account cannot be posted to rather than merely being badged.
        expect($account->fresh()->chartAccount->status)->toBe(ActiveStatus::Inactive);
    });

    it('lets the same account keep its own number', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/bank-accounts', bankAccountPayload())->assertCreated();
        $account = BankAccount::query()->where('account_number', '0150999888777')->firstOrFail();

        $this->putJson("/api/v1/bank-accounts/{$account->id}", bankAccountPayload([
            'description' => 'Updated description.',
        ]))->assertOk();
    });
});

describe('closing', function (): void {
    it('soft-deletes and deactivates the chart account', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/bank-accounts', bankAccountPayload())->assertCreated();
        $account = BankAccount::query()->where('account_number', '0150999888777')->firstOrFail();
        $chartId = $account->chart_account_id;

        $this->deleteJson("/api/v1/bank-accounts/{$account->id}")->assertOk();

        expect(BankAccount::query()->find($account->id))->toBeNull();
        expect(BankAccount::query()->withTrashed()->find($account->id))->not->toBeNull();

        // Never deleted: it holds everything that ever passed through.
        expect(ChartOfAccount::query()->findOrFail($chartId)->status)->toBe(ActiveStatus::Inactive);
    });

    it('refuses while the account still holds money', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/bank-accounts', bankAccountPayload(['openingBalance' => 500000]))
            ->assertCreated();
        $account = BankAccount::query()->where('account_number', '0150999888777')->firstOrFail();

        $this->deleteJson("/api/v1/bank-accounts/{$account->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');
    });
});

describe('account balance', function (): void {
    it('reports the balance from the ledger', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/bank-accounts', bankAccountPayload(['openingBalance' => 500000]))
            ->assertCreated();

        // Located by number rather than by position: the list is ordered by
        // bank and account name, and the seeded accounts sort before this one.
        $row = collect($this->getJson('/api/v1/bank-accounts')->assertOk()->json('data'))
            ->firstWhere('accountNumber', '0150999888777');

        expect($row['balance'])->toBe('500000.00');
    });

    it('reports no movement unless asked, and the day movement when asked', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/bank-accounts', bankAccountPayload(['openingBalance' => 500000]))
            ->assertCreated();

        $without = collect($this->getJson('/api/v1/bank-accounts')->assertOk()->json('data'))
            ->firstWhere('accountNumber', '0150999888777');
        expect($without['todayDeposit'])->toBe('0.00');

        // The opening entry is dated today, so it shows as the day's deposit.
        $with = collect($this->getJson('/api/v1/bank-accounts?with_movement=1')->assertOk()->json('data'))
            ->firstWhere('accountNumber', '0150999888777');

        expect($with['todayDeposit'])->toBe('500000.00');
        expect($with['todayWithdrawal'])->toBe('0.00');
    });

    it('returns the shape the frontend schema declares', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/bank-accounts', bankAccountPayload())->assertCreated();

        $this->getJson('/api/v1/bank-accounts')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'bankName', 'accountName', 'accountNumber', 'branch',
                    'currency', 'openingBalance', 'balance', 'status', 'description',
                    'todayDeposit', 'todayWithdrawal',
                ]],
            ]);
    });
});

describe('authorization', function (): void {
    it('lets a read-only treasury role list but not register', function (): void {
        actingAsRole(RoleName::Auditor);

        $this->getJson('/api/v1/bank-accounts')->assertOk();
        $this->postJson('/api/v1/bank-accounts', bankAccountPayload())->assertForbidden();
    });

    it('refuses an unauthenticated caller', function (): void {
        $this->getJson('/api/v1/bank-accounts')->assertUnauthorized();
    });
});
