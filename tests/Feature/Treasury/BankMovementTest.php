<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\BankTransfer;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\User;

/**
 * Bank → Bank Transaction, Approved Transaction, and the two Transfer Balance
 * screens.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

/**
 * A registered account holding money.
 *
 * Funded through the real endpoint rather than by writing a balance, so the
 * ledger it draws on is one the posting engine actually produced.
 */
function fundedBankAccount(string $opening = '1000000'): BankAccount
{
    test()->postJson('/api/v1/bank-accounts', [
        'bankName' => 'CRDB Bank',
        'accountName' => 'Mikopofasta Operations',
        'accountNumber' => '0150999888777',
        'currency' => 'TZS',
        'openingBalance' => $opening,
        'status' => 'active',
        'description' => null,
    ])->assertCreated();

    return BankAccount::query()->where('account_number', '0150999888777')->firstOrFail();
}

function secondBankAccount(): BankAccount
{
    test()->postJson('/api/v1/bank-accounts', [
        'bankName' => 'NMB Bank',
        'accountName' => 'Salary Advance & Disbursement',
        'accountNumber' => '2011000111222',
        'currency' => 'TZS',
        'openingBalance' => 0,
        'status' => 'active',
        'description' => null,
    ])->assertCreated();

    return BankAccount::query()->where('account_number', '2011000111222')->firstOrFail();
}

function bankBalanceOf(BankAccount $account): float
{
    return (float) $account->fresh()->load('chartAccount.balances')
        ->currentBalance()->toDecimalString();
}

function tellerBalanceOf(Branch $branch): float
{
    return (float) app(AccountResolver::class)->tellerCash($branch)
        ->load('balances')->cachedBalance()->toDecimalString();
}

function switchToBankApprover(): User
{
    forgetAuthGuards();

    return officerAt('Head Office', RoleName::Finance);
}

function latestBankTransactionId(): int
{
    return (int) BankTransaction::query()->latest('id')->firstOrFail()->getKey();
}

describe('raising a transaction', function (): void {
    it('records it pending and posts nothing', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $account = fundedBankAccount();
        $entriesBefore = JournalEntry::query()->count();

        $this->postJson('/api/v1/bank-transactions', [
            'bankAccountId' => $account->id,
            'type' => 'deposit',
            'amount' => '250000',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.journalEntryId', null);

        expect(JournalEntry::query()->count())->toBe($entriesBefore);
    });

    it('numbers transactions BNK-0000001 upward', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $account = fundedBankAccount();

        $this->postJson('/api/v1/bank-transactions', [
            'bankAccountId' => $account->id,
            'type' => 'deposit',
            'amount' => '250000',
        ])->assertCreated()->assertJsonPath('data.reference', 'BNK-0000001');
    });

    it('defaults to the requester branch', function (): void {
        $user = officerAt('Kakonko', RoleName::Finance);
        $account = fundedBankAccount();

        $this->postJson('/api/v1/bank-transactions', [
            'bankAccountId' => $account->id,
            'type' => 'deposit',
            'amount' => '250000',
        ])->assertCreated()->assertJsonPath('data.branch', 'Kakonko');

        expect(BankTransaction::query()->latest('id')->first()->branch_id)->toBe($user->branch_id);
    });
});

describe('approving a deposit', function (): void {
    it('moves cash from the branch till into the bank', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $account = fundedBankAccount();
        $kakonko = Branch::query()->where('name', 'Kakonko')->firstOrFail();

        $bankBefore = bankBalanceOf($account);
        $tillBefore = tellerBalanceOf($kakonko);

        $this->postJson('/api/v1/bank-transactions', [
            'bankAccountId' => $account->id,
            'type' => 'deposit',
            'amount' => '250000',
        ])->assertCreated();

        $id = latestBankTransactionId();
        switchToBankApprover();
        $this->postJson("/api/v1/bank-transactions/{$id}/decide", ['decision' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Both sides are the company's own money; nothing is earned.
        expect(bankBalanceOf($account) - $bankBefore)->toBe(250000.0);
        expect($tillBefore - tellerBalanceOf($kakonko))->toBe(250000.0);
    });

    it('posts a balanced entry', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $account = fundedBankAccount();

        $this->postJson('/api/v1/bank-transactions', [
            'bankAccountId' => $account->id,
            'type' => 'deposit',
            'amount' => '250000',
        ])->assertCreated();

        $id = latestBankTransactionId();
        switchToBankApprover();
        $this->postJson("/api/v1/bank-transactions/{$id}/decide", ['decision' => 'approved'])->assertOk();

        $entry = JournalEntry::query()->with('lines')->latest('id')->firstOrFail();
        expect($entry->lines->sum(fn ($l) => (float) $l->debit_amount))
            ->toBe($entry->lines->sum(fn ($l) => (float) $l->credit_amount));
    });
});

describe('approving a withdrawal', function (): void {
    it('refuses to overdraw the account', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $account = fundedBankAccount('100000');

        $this->postJson('/api/v1/bank-transactions', [
            'bankAccountId' => $account->id,
            'type' => 'withdrawal',
            'amount' => '500000',
        ])->assertCreated();

        $id = latestBankTransactionId();
        switchToBankApprover();

        // The ledger would allow a negative asset balance; a real bank account
        // would not, and reporting money the company does not have is worse
        // than refusing the movement.
        $this->postJson("/api/v1/bank-transactions/{$id}/decide", ['decision' => 'approved'])
            ->assertStatus(422);

        expect(bankBalanceOf($account))->toBe(100000.0);
        expect(BankTransaction::query()->find($id)->status->value)->toBe('pending');
    });

    it('draws cash out to the branch till', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $account = fundedBankAccount();
        $kakonko = Branch::query()->where('name', 'Kakonko')->firstOrFail();
        $tillBefore = tellerBalanceOf($kakonko);

        $this->postJson('/api/v1/bank-transactions', [
            'bankAccountId' => $account->id,
            'type' => 'withdrawal',
            'amount' => '300000',
        ])->assertCreated();

        $id = latestBankTransactionId();
        switchToBankApprover();
        $this->postJson("/api/v1/bank-transactions/{$id}/decide", ['decision' => 'approved'])->assertOk();

        expect(bankBalanceOf($account))->toBe(700000.0);
        expect(tellerBalanceOf($kakonko) - $tillBefore)->toBe(300000.0);
    });
});

describe('approving a charge', function (): void {
    it('books the fee to Bank Charges rather than to write-off', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $account = fundedBankAccount();

        $this->postJson('/api/v1/bank-transactions', [
            'bankAccountId' => $account->id,
            'type' => 'charge',
            'amount' => '5000',
        ])->assertCreated();

        $id = latestBankTransactionId();
        switchToBankApprover();
        $this->postJson("/api/v1/bank-transactions/{$id}/decide", ['decision' => 'approved'])->assertOk();

        // A bank's fee is an operating cost. Booking it to 4200 Write-Off would
        // overstate credit losses by the price of running a bank account.
        $charges = app(AccountResolver::class)->system(SystemAccountCode::BankCharges)
            ->load('balances');

        expect((float) $charges->cachedBalance()->toDecimalString())->toBe(5000.0);
        expect(bankBalanceOf($account))->toBe(995000.0);
    });
});

describe('deciding', function (): void {
    it('posts nothing when rejected', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $account = fundedBankAccount();

        $this->postJson('/api/v1/bank-transactions', [
            'bankAccountId' => $account->id,
            'type' => 'deposit',
            'amount' => '250000',
        ])->assertCreated();

        $id = latestBankTransactionId();
        $before = bankBalanceOf($account);
        switchToBankApprover();

        $this->postJson("/api/v1/bank-transactions/{$id}/decide", [
            'decision' => 'rejected',
            'note' => 'Deposit slip missing.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.journalEntryId', null);

        expect(bankBalanceOf($account))->toBe($before);
    });

    it('refuses to decide the same transaction twice', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $account = fundedBankAccount();

        $this->postJson('/api/v1/bank-transactions', [
            'bankAccountId' => $account->id,
            'type' => 'deposit',
            'amount' => '250000',
        ])->assertCreated();

        $id = latestBankTransactionId();
        switchToBankApprover();
        $this->postJson("/api/v1/bank-transactions/{$id}/decide", ['decision' => 'approved'])->assertOk();

        $this->postJson("/api/v1/bank-transactions/{$id}/decide", ['decision' => 'approved'])
            ->assertStatus(409);

        expect(bankBalanceOf($account))->toBe(1250000.0);
    });

    it('will not let the requester approve their own transaction', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $account = fundedBankAccount();

        $this->postJson('/api/v1/bank-transactions', [
            'bankAccountId' => $account->id,
            'type' => 'deposit',
            'amount' => '250000',
        ])->assertCreated();

        $this->postJson('/api/v1/bank-transactions/'.latestBankTransactionId().'/decide', [
            'decision' => 'approved',
        ])->assertForbidden();
    });

    it('records the decision', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $account = fundedBankAccount();

        $this->postJson('/api/v1/bank-transactions', [
            'bankAccountId' => $account->id,
            'type' => 'deposit',
            'amount' => '250000',
        ])->assertCreated();

        $id = latestBankTransactionId();
        switchToBankApprover();
        $this->postJson("/api/v1/bank-transactions/{$id}/decide", ['decision' => 'approved'])->assertOk();

        expect(AuditLog::query()->where('action', AuditAction::BankTransactionApproved->value)->exists())
            ->toBeTrue();
    });
});

describe('transfers', function (): void {
    it('applies immediately, with the charge posted separately', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $from = fundedBankAccount();
        $kakonko = Branch::query()->where('name', 'Kakonko')->firstOrFail();
        $tillBefore = tellerBalanceOf($kakonko);

        $this->postJson('/api/v1/bank-transfers', [
            'kind' => 'branch',
            'fromAccountId' => $from->id,
            'toBranchId' => $kakonko->id,
            'amount' => '200000',
            'chargeFee' => '2000',
            'reason' => 'Branch float',
        ])
            ->assertCreated()
            // No approval step: the legacy screens show none, and this is one
            // person moving the company's own money between its own accounts.
            ->assertJsonPath('data.status', 'completed');

        // The destination receives the full amount; the charge is a separate
        // cost rather than being netted off it.
        expect(tellerBalanceOf($kakonko) - $tillBefore)->toBe(200000.0);
        expect(bankBalanceOf($from))->toBe(798000.0);

        $charges = app(AccountResolver::class)->system(SystemAccountCode::BankCharges)->load('balances');
        expect((float) $charges->cachedBalance()->toDecimalString())->toBe(2000.0);
    });

    it('posts a balanced three-line entry when there is a charge', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $from = fundedBankAccount();

        $this->postJson('/api/v1/bank-transfers', [
            'kind' => 'branch',
            'fromAccountId' => $from->id,
            'toBranchId' => Branch::query()->where('name', 'Kakonko')->value('id'),
            'amount' => '200000',
            'chargeFee' => '2000',
            'reason' => 'Branch float',
        ])->assertCreated();

        $entry = JournalEntry::query()->with('lines')->latest('id')->firstOrFail();
        expect($entry->lines)->toHaveCount(3);
        expect($entry->lines->sum(fn ($l) => (float) $l->debit_amount))
            ->toBe($entry->lines->sum(fn ($l) => (float) $l->credit_amount));
    });

    it('posts two lines when there is no charge', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $from = fundedBankAccount();

        $this->postJson('/api/v1/bank-transfers', [
            'kind' => 'branch',
            'fromAccountId' => $from->id,
            'toBranchId' => Branch::query()->where('name', 'Kakonko')->value('id'),
            'amount' => '200000',
            'reason' => 'Branch float',
        ])->assertCreated();

        // A zero-amount line is not a line, and LedgerService rejects one.
        expect(JournalEntry::query()->with('lines')->latest('id')->firstOrFail()->lines)->toHaveCount(2);
    });

    it('moves money to another bank account for a salary advance transfer', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $from = fundedBankAccount();
        $to = secondBankAccount();

        $this->postJson('/api/v1/bank-transfers', [
            'kind' => 'salary_advance',
            'fromAccountId' => $from->id,
            'toAccountId' => $to->id,
            'amount' => '400000',
            'reason' => 'Fund the advance account',
        ])->assertCreated();

        expect(bankBalanceOf($from))->toBe(600000.0);
        expect(bankBalanceOf($to))->toBe(400000.0);
    });

    it('refuses a branch transfer that names an account instead', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $from = fundedBankAccount();
        $to = secondBankAccount();

        $this->postJson('/api/v1/bank-transfers', [
            'kind' => 'branch',
            'fromAccountId' => $from->id,
            'toAccountId' => $to->id,
            'amount' => '100000',
            'reason' => 'Wrong destination',
        ])->assertStatus(422);
    });

    it('refuses a transfer to the account it came from', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $from = fundedBankAccount();

        $this->postJson('/api/v1/bank-transfers', [
            'kind' => 'salary_advance',
            'fromAccountId' => $from->id,
            'toAccountId' => $from->id,
            'amount' => '100000',
            'reason' => 'Nowhere',
        ])->assertStatus(422);
    });

    it('refuses a transfer the account cannot cover once the charge is added', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $from = fundedBankAccount('200000');

        // The amount alone fits; the amount plus the charge does not, and both
        // leave the account.
        $this->postJson('/api/v1/bank-transfers', [
            'kind' => 'branch',
            'fromAccountId' => $from->id,
            'toBranchId' => Branch::query()->where('name', 'Kakonko')->value('id'),
            'amount' => '200000',
            'chargeFee' => '2000',
            'reason' => 'Branch float',
        ])->assertStatus(422);

        expect(bankBalanceOf($from))->toBe(200000.0);
        expect(BankTransfer::query()->count())->toBe(0);
    });

    it('totals amounts and charges separately', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $from = fundedBankAccount();
        $kakonko = Branch::query()->where('name', 'Kakonko')->value('id');

        foreach ([['100000', '1000'], ['150000', '1500']] as [$amount, $fee]) {
            $this->postJson('/api/v1/bank-transfers', [
                'kind' => 'branch',
                'fromAccountId' => $from->id,
                'toBranchId' => $kakonko,
                'amount' => $amount,
                'chargeFee' => $fee,
                'reason' => 'Branch float',
            ])->assertCreated();
        }

        $this->getJson('/api/v1/bank-transfers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', '250000.00')
            // Kept apart so the cost of banking is visible as a cost.
            ->assertJsonPath('meta.chargesTotal', '2500.00');
    });
});

describe('authorization', function (): void {
    it('denies a role without treasury.view', function (): void {
        actingAsRole(RoleName::LoanOfficer);

        $this->getJson('/api/v1/bank-transactions')->assertForbidden();
        $this->getJson('/api/v1/bank-transfers')->assertForbidden();
    });

    it('refuses an unauthenticated caller', function (): void {
        $this->getJson('/api/v1/bank-transactions')->assertUnauthorized();
    });
});
