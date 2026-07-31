<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\HqAccount;
use App\Models\HqAccountTransfer;
use App\Models\User;

/**
 * Headquarters Transaction → Account Balance, Requested Transactions,
 * Approved Transactions.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
    test()->seed(Database\Seeders\HqAccountSeeder::class);
});

function hqAccountNamed(string $name): HqAccount
{
    return HqAccount::query()->where('name', $name)->firstOrFail();
}

function hqBalanceOf(string $name): float
{
    return (float) hqAccountNamed($name)->balance;
}

/** §14 needs a second identity for every approval. */
function switchToHqApprover(): User
{
    forgetAuthGuards();

    return officerAt('Head Office', RoleName::Finance);
}

function latestHqTransactionId(): int
{
    return (int) HqAccountTransfer::query()->latest('id')->firstOrFail()->getKey();
}

describe('account balance', function (): void {
    it('returns the seven legacy accounts and their printed total', function (): void {
        officerAt('Head Office', RoleName::Finance);

        // The seven balances sum to exactly what the legacy screen prints;
        // HqAccountSeeder refuses to run if they ever stop doing so.
        $this->getJson('/api/v1/hq-accounts')
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('meta.total', '8667270.00');
    });

    it('names each account exactly as the legacy system holds it', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->getJson('/api/v1/hq-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'SALARY ADVANCE ACCOUNT');
    });
});

describe('raising a movement', function (): void {
    it('records it pending and moves no balance', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $before = hqBalanceOf('INTEREST ACCOUNT');

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'out',
            'fromAccountId' => hqAccountNamed('INTEREST ACCOUNT')->id,
            'amount' => '50000',
            'reason' => 'Head office electricity',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.direction', 'out');

        // A queue of requests must never show in the balance screen.
        expect(hqBalanceOf('INTEREST ACCOUNT'))->toBe($before);
    });

    it('numbers movements HQT-0000001 upward', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'in',
            'toAccountId' => hqAccountNamed('RESERVE ACCOUNT')->id,
            'amount' => '10000',
            'reason' => 'Recovery received',
        ])->assertCreated()->assertJsonPath('data.reference', 'HQT-0000001');
    });

    it('refuses money arriving that names where it came from', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'in',
            'fromAccountId' => hqAccountNamed('INTEREST ACCOUNT')->id,
            'toAccountId' => hqAccountNamed('RESERVE ACCOUNT')->id,
            'amount' => '10000',
            'reason' => 'Nonsense',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
    });

    it('refuses money leaving that names no source', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'out',
            'amount' => '10000',
            'reason' => 'Nonsense',
        ])->assertStatus(422);
    });

    it('refuses a transfer with only one side', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'internal',
            'fromAccountId' => hqAccountNamed('INTEREST ACCOUNT')->id,
            'amount' => '10000',
            'reason' => 'Nonsense',
        ])->assertStatus(422);
    });

    it('refuses a transfer to the same account', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $id = hqAccountNamed('INTEREST ACCOUNT')->id;

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'internal',
            'fromAccountId' => $id,
            'toAccountId' => $id,
            'amount' => '10000',
            'reason' => 'Nowhere',
        ])->assertStatus(422);
    });

    it('requires a reason', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'in',
            'toAccountId' => hqAccountNamed('RESERVE ACCOUNT')->id,
            'amount' => '10000',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', 'Say what this movement is for.');
    });
});

describe('approval', function (): void {
    it('draws down the source pot', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $before = hqBalanceOf('INTEREST ACCOUNT');

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'out',
            'fromAccountId' => hqAccountNamed('INTEREST ACCOUNT')->id,
            'amount' => '50000',
            'reason' => 'Head office electricity',
        ])->assertCreated();

        $id = latestHqTransactionId();
        switchToHqApprover();

        $this->postJson("/api/v1/hq-transactions/{$id}/decide", ['decision' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        expect($before - hqBalanceOf('INTEREST ACCOUNT'))->toBe(50000.0);
    });

    it('adds to the destination pot', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $before = hqBalanceOf('RESERVE ACCOUNT');

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'in',
            'toAccountId' => hqAccountNamed('RESERVE ACCOUNT')->id,
            'amount' => '25000',
            'reason' => 'Recovery received',
        ])->assertCreated();

        $id = latestHqTransactionId();
        switchToHqApprover();
        $this->postJson("/api/v1/hq-transactions/{$id}/decide", ['decision' => 'approved'])->assertOk();

        expect(hqBalanceOf('RESERVE ACCOUNT') - $before)->toBe(25000.0);
    });

    it('leaves the headquarters total unchanged on an internal transfer', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'internal',
            'fromAccountId' => hqAccountNamed('INTEREST ACCOUNT')->id,
            'toAccountId' => hqAccountNamed('RESERVE ACCOUNT')->id,
            'amount' => '100000',
            'reason' => 'Top up the reserve',
        ])->assertCreated();

        $id = latestHqTransactionId();
        switchToHqApprover();
        $this->postJson("/api/v1/hq-transactions/{$id}/decide", ['decision' => 'approved'])->assertOk();

        // Which pot holds the cash changed; how much there is did not.
        $this->getJson('/api/v1/hq-accounts')
            ->assertOk()
            ->assertJsonPath('meta.total', '8667270.00');

        expect(hqBalanceOf('INTEREST ACCOUNT'))->toBe(659790.0);
        expect(hqBalanceOf('RESERVE ACCOUNT'))->toBe(321900.0);
    });

    it('refuses to overdraw a pot', function (): void {
        officerAt('Head Office', RoleName::Finance);

        // LOAN FEE ACCOUNT holds 97,000.
        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'out',
            'fromAccountId' => hqAccountNamed('LOAN FEE ACCOUNT')->id,
            'amount' => '200000',
            'reason' => 'More than there is',
        ])->assertCreated();

        $id = latestHqTransactionId();
        switchToHqApprover();

        // The balance is stored, not derived, so nothing else would catch this.
        $this->postJson("/api/v1/hq-transactions/{$id}/decide", ['decision' => 'approved'])
            ->assertStatus(422);

        expect(hqBalanceOf('LOAN FEE ACCOUNT'))->toBe(97000.0);
        expect(HqAccountTransfer::query()->find($id)->status->value)->toBe('pending');
    });

    it('moves nothing when rejected', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $before = hqBalanceOf('INTEREST ACCOUNT');

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'out',
            'fromAccountId' => hqAccountNamed('INTEREST ACCOUNT')->id,
            'amount' => '50000',
            'reason' => 'Not justified',
        ])->assertCreated();

        $id = latestHqTransactionId();
        switchToHqApprover();
        $this->postJson("/api/v1/hq-transactions/{$id}/decide", ['decision' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        expect(hqBalanceOf('INTEREST ACCOUNT'))->toBe($before);
    });

    it('refuses to decide the same movement twice', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'out',
            'fromAccountId' => hqAccountNamed('INTEREST ACCOUNT')->id,
            'amount' => '50000',
            'reason' => 'Head office electricity',
        ])->assertCreated();

        $id = latestHqTransactionId();
        switchToHqApprover();
        $this->postJson("/api/v1/hq-transactions/{$id}/decide", ['decision' => 'approved'])->assertOk();

        // Otherwise the pot would be drawn down twice for one movement.
        $this->postJson("/api/v1/hq-transactions/{$id}/decide", ['decision' => 'approved'])
            ->assertStatus(409);

        expect(hqBalanceOf('INTEREST ACCOUNT'))->toBe(709790.0);
    });

    it('will not let the requester approve their own movement', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'out',
            'fromAccountId' => hqAccountNamed('INTEREST ACCOUNT')->id,
            'amount' => '50000',
            'reason' => 'Head office electricity',
        ])->assertCreated();

        $id = latestHqTransactionId();

        $this->postJson("/api/v1/hq-transactions/{$id}/decide", ['decision' => 'approved'])
            ->assertForbidden();
    });

    it('records the decision, since no journal entry does', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'out',
            'fromAccountId' => hqAccountNamed('INTEREST ACCOUNT')->id,
            'amount' => '50000',
            'reason' => 'Head office electricity',
        ])->assertCreated();

        $id = latestHqTransactionId();
        switchToHqApprover();
        $this->postJson("/api/v1/hq-transactions/{$id}/decide", ['decision' => 'approved'])->assertOk();

        // The seven pots sit outside the §5 ledger, so the audit trail is the
        // only record of who moved what.
        expect(AuditLog::query()->where('action', AuditAction::HqTransactionApproved->value)->exists())
            ->toBeTrue();
    });
});

describe('listing', function (): void {
    it('summarises the position from approved movements only', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'in',
            'toAccountId' => hqAccountNamed('RESERVE ACCOUNT')->id,
            'amount' => '80000',
            'reason' => 'Recovery received',
        ])->assertCreated();
        $approvedId = latestHqTransactionId();

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'out',
            'fromAccountId' => hqAccountNamed('INTEREST ACCOUNT')->id,
            'amount' => '30000',
            'reason' => 'Still waiting on a decision',
        ])->assertCreated();

        switchToHqApprover();
        $this->postJson("/api/v1/hq-transactions/{$approvedId}/decide", ['decision' => 'approved'])->assertOk();

        $this->getJson('/api/v1/hq-transactions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // The pending 30,000 counts towards nothing.
            ->assertJsonPath('meta.income', '80000.00')
            ->assertJsonPath('meta.expense', '0.00')
            ->assertJsonPath('meta.net', '80000.00');
    });

    it('counts an internal transfer as neither income nor expense', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'internal',
            'fromAccountId' => hqAccountNamed('INTEREST ACCOUNT')->id,
            'toAccountId' => hqAccountNamed('RESERVE ACCOUNT')->id,
            'amount' => '100000',
            'reason' => 'Top up the reserve',
        ])->assertCreated();

        $id = latestHqTransactionId();
        switchToHqApprover();
        $this->postJson("/api/v1/hq-transactions/{$id}/decide", ['decision' => 'approved'])->assertOk();

        $this->getJson('/api/v1/hq-transactions')
            ->assertOk()
            ->assertJsonPath('meta.income', '0.00')
            ->assertJsonPath('meta.expense', '0.00')
            ->assertJsonPath('meta.net', '0.00');
    });

    it('filters by status and direction', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'in',
            'toAccountId' => hqAccountNamed('RESERVE ACCOUNT')->id,
            'amount' => '80000',
            'reason' => 'Recovery received',
        ])->assertCreated();

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'out',
            'fromAccountId' => hqAccountNamed('INTEREST ACCOUNT')->id,
            'amount' => '30000',
            'reason' => 'Electricity',
        ])->assertCreated();

        $this->getJson('/api/v1/hq-transactions?direction=in')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/hq-transactions?status=approved')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('returns the flat names the frontend schema declares', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'out',
            'fromAccountId' => hqAccountNamed('INTEREST ACCOUNT')->id,
            'branchId' => App\Models\Branch::query()->where('name', 'Kakonko')->value('id'),
            'amount' => '30000',
            'reason' => 'Electricity',
        ])->assertCreated();

        $this->getJson('/api/v1/hq-transactions')
            ->assertOk()
            ->assertJsonPath('data.0.branch', 'Kakonko')
            ->assertJsonStructure([
                'data' => [[
                    'id', 'reference', 'branch', 'requestedBy', 'approvedBy',
                    'amount', 'reason', 'status', 'date', 'direction',
                ]],
            ]);
    });
});

describe('authorization', function (): void {
    it('lets a read-only treasury role list but not raise', function (): void {
        actingAsRole(RoleName::Auditor);

        $this->getJson('/api/v1/hq-accounts')->assertOk();
        $this->getJson('/api/v1/hq-transactions')->assertOk();

        $this->postJson('/api/v1/hq-transactions', [
            'direction' => 'in',
            'toAccountId' => hqAccountNamed('RESERVE ACCOUNT')->id,
            'amount' => '10000',
            'reason' => 'Not allowed',
        ])->assertForbidden();
    });

    it('refuses an unauthenticated caller', function (): void {
        $this->getJson('/api/v1/hq-accounts')->assertUnauthorized();
        $this->getJson('/api/v1/hq-transactions')->assertUnauthorized();
    });
});
