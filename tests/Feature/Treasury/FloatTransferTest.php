<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\Services\AccountResolver;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FloatTransfer;
use App\Models\JournalEntry;

/** Capital → Float / Float Branch To Branch / Aproved Float / Float Ac-Ac. */
beforeEach(function (): void {
    seedLedgerFoundation();
});

function branchNamed(string $name): Branch
{
    return Branch::query()->where('name', $name)->firstOrFail();
}

function tellBalance(Branch $branch): float
{
    $account = app(AccountResolver::class)->tellerCash($branch);

    return (float) $account->load('balances')->cachedBalance()->toDecimalString();
}

describe('company to branch', function (): void {
    it('applies immediately and moves cash from head office to the branch', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $kakonko = branchNamed('Kakonko');

        $before = tellBalance($kakonko);

        $this->postJson('/api/v1/float-transfers', [
            'kind' => 'company_to_branch',
            'toBranchId' => $kakonko->id,
            'amount' => '100000',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved');

        // No approval step: the legacy screen shows no status for this kind.
        $transfer = FloatTransfer::query()->latest('id')->firstOrFail();
        expect($transfer->journal_entry_id)->not->toBeNull();
        expect(tellBalance($kakonko) - $before)->toBe(100000.0);
    });

    it('posts a balanced entry', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/float-transfers', [
            'kind' => 'company_to_branch',
            'toBranchId' => branchNamed('Kakonko')->id,
            'amount' => '100000',
        ])->assertCreated();

        $entry = JournalEntry::query()->with('lines')->latest('id')->firstOrFail();
        expect($entry->lines)->toHaveCount(2);
        expect($entry->lines->sum(fn ($l) => (float) $l->debit_amount))
            ->toBe($entry->lines->sum(fn ($l) => (float) $l->credit_amount));
    });
});

describe('branch to branch', function (): void {
    it('is raised pending and posts nothing until approved', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $from = branchNamed('Kakonko');
        $to = branchNamed('Missenyi');

        $entriesBefore = JournalEntry::query()->count();

        $this->postJson('/api/v1/float-transfers', [
            'kind' => 'branch_to_branch',
            'fromBranchId' => $from->id,
            'toBranchId' => $to->id,
            'amount' => '50000',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.statusLabel', 'PENDING');

        // A queue of requests must never affect the trial balance.
        expect(JournalEntry::query()->count())->toBe($entriesBefore);
        expect(FloatTransfer::query()->latest('id')->first()->journal_entry_id)->toBeNull();
    });

    it('moves the money only on approval', function (): void {
        $requester = officerAt('Head Office', RoleName::Finance);
        $to = branchNamed('Missenyi');
        $before = tellBalance($to);

        $id = $this->postJson('/api/v1/float-transfers', [
            'kind' => 'branch_to_branch',
            'fromBranchId' => branchNamed('Kakonko')->id,
            'toBranchId' => $to->id,
            'amount' => '50000',
        ])->json('data.id');

        expect(tellBalance($to))->toBe($before);

        // §14: a different person approves.
        officerAt('Head Office', RoleName::SuperAdmin);
        $this->postJson("/api/v1/float-transfers/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        expect(tellBalance($to) - $before)->toBe(50000.0);
        expect($requester->id)->not->toBeNull();
    });

    it('refuses to let the requester approve their own transfer', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $id = $this->postJson('/api/v1/float-transfers', [
            'kind' => 'branch_to_branch',
            'fromBranchId' => branchNamed('Kakonko')->id,
            'toBranchId' => branchNamed('Missenyi')->id,
            'amount' => '50000',
        ])->json('data.id');

        // Same actor — separation of duties must refuse.
        $this->postJson("/api/v1/float-transfers/{$id}/approve")->assertForbidden();
    });

    it('rejects with a reason and posts nothing', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $id = $this->postJson('/api/v1/float-transfers', [
            'kind' => 'branch_to_branch',
            'fromBranchId' => branchNamed('Kakonko')->id,
            'toBranchId' => branchNamed('Missenyi')->id,
            'amount' => '50000',
        ])->json('data.id');

        $entriesBefore = JournalEntry::query()->count();

        officerAt('Head Office', RoleName::SuperAdmin);
        $this->postJson("/api/v1/float-transfers/{$id}/reject", ['reason' => 'Not needed this week'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejectionReason', 'Not needed this week');

        expect(JournalEntry::query()->count())->toBe($entriesBefore);
    });

    it('requires a reason to reject', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $id = $this->postJson('/api/v1/float-transfers', [
            'kind' => 'branch_to_branch',
            'fromBranchId' => branchNamed('Kakonko')->id,
            'toBranchId' => branchNamed('Missenyi')->id,
            'amount' => '50000',
        ])->json('data.id');

        officerAt('Head Office', RoleName::SuperAdmin);
        $this->postJson("/api/v1/float-transfers/{$id}/reject", ['reason' => ''])->assertStatus(422);
    });

    it('cannot decide a transfer twice', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $id = $this->postJson('/api/v1/float-transfers', [
            'kind' => 'branch_to_branch',
            'fromBranchId' => branchNamed('Kakonko')->id,
            'toBranchId' => branchNamed('Missenyi')->id,
            'amount' => '50000',
        ])->json('data.id');

        officerAt('Head Office', RoleName::SuperAdmin);
        $this->postJson("/api/v1/float-transfers/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/float-transfers/{$id}/approve")->assertStatus(409);
    });

    it('refuses the same branch on both sides', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $kakonko = branchNamed('Kakonko');

        $this->postJson('/api/v1/float-transfers', [
            'kind' => 'branch_to_branch',
            'fromBranchId' => $kakonko->id,
            'toBranchId' => $kakonko->id,
            'amount' => '50000',
        ])->assertStatus(422)->assertJsonPath('errors.toBranchId.0', 'Choose two different branches.');
    });
});

describe('deleting', function (): void {
    it('deletes a pending transfer but not an approved one', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $id = $this->postJson('/api/v1/float-transfers', [
            'kind' => 'branch_to_branch',
            'fromBranchId' => branchNamed('Kakonko')->id,
            'toBranchId' => branchNamed('Missenyi')->id,
            'amount' => '50000',
        ])->json('data.id');

        $this->deleteJson("/api/v1/float-transfers/{$id}")->assertOk();
        expect(FloatTransfer::query()->find($id))->toBeNull();

        // An applied transfer has moved money — deleting it would orphan a
        // posting, so it is refused.
        $applied = $this->postJson('/api/v1/float-transfers', [
            'kind' => 'company_to_branch',
            'toBranchId' => branchNamed('Kakonko')->id,
            'amount' => '10000',
        ])->json('data.id');

        $this->deleteJson("/api/v1/float-transfers/{$applied}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');
    });
});

describe('account to account', function (): void {
    it('moves between two named accounts of a branch', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $resolver = app(AccountResolver::class);
        $from = $resolver->tellerCash(branchNamed('Kakonko'));
        $to = $resolver->defaultBankAccount();

        $this->postJson('/api/v1/float-transfers', [
            'kind' => 'account_to_account',
            'toBranchId' => branchNamed('Kakonko')->id,
            'fromAccountId' => $from->id,
            'toAccountId' => $to->id,
            'amount' => '25000',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved');

        $entry = JournalEntry::query()->with('lines')->latest('id')->firstOrFail();
        expect($entry->lines->firstWhere('account_id', $to->id)->debit_amount)->toBe('25000.00');
        expect($entry->lines->firstWhere('account_id', $from->id)->credit_amount)->toBe('25000.00');
    });

    it('refuses the same account on both sides', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $account = app(AccountResolver::class)->tellerCash(branchNamed('Kakonko'));

        $this->postJson('/api/v1/float-transfers', [
            'kind' => 'account_to_account',
            'toBranchId' => branchNamed('Kakonko')->id,
            'fromAccountId' => $account->id,
            'toAccountId' => $account->id,
            'amount' => '25000',
        ])->assertStatus(422)->assertJsonPath('errors.toAccountId.0', 'Choose two different accounts.');
    });
});

describe('listing', function (): void {
    it('filters by kind and status and totals what it returns', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/float-transfers', [
            'kind' => 'company_to_branch', 'toBranchId' => branchNamed('Kakonko')->id, 'amount' => '100000',
        ])->assertCreated();
        $this->postJson('/api/v1/float-transfers', [
            'kind' => 'branch_to_branch',
            'fromBranchId' => branchNamed('Kakonko')->id,
            'toBranchId' => branchNamed('Missenyi')->id,
            'amount' => '50000',
        ])->assertCreated();

        $company = $this->getJson('/api/v1/float-transfers?kind=company_to_branch')->assertOk();
        expect($company->json('data'))->toHaveCount(1);
        expect($company->json('meta.total'))->toBe('100000.00');

        $pending = $this->getJson('/api/v1/float-transfers?status=pending')->assertOk();
        expect($pending->json('data'))->toHaveCount(1);
        expect($pending->json('data.0.kind'))->toBe('branch_to_branch');
    });
});

describe('rbac', function (): void {
    it('requires treasury.view to read and treasury.manage to raise', function (): void {
        officerAt('Head Office', RoleName::Admin);
        $this->getJson('/api/v1/float-transfers')->assertOk();
        $this->postJson('/api/v1/float-transfers', [
            'kind' => 'company_to_branch', 'toBranchId' => branchNamed('Kakonko')->id, 'amount' => '100000',
        ])->assertForbidden();

        officerAt('Kakonko', RoleName::LoanOfficer);
        $this->getJson('/api/v1/float-transfers')->assertForbidden();
    });
});

describe('audit trail', function (): void {
    it('records the request and the decision separately', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $id = $this->postJson('/api/v1/float-transfers', [
            'kind' => 'branch_to_branch',
            'fromBranchId' => branchNamed('Kakonko')->id,
            'toBranchId' => branchNamed('Missenyi')->id,
            'amount' => '50000',
        ])->json('data.id');

        $approver = officerAt('Head Office', RoleName::SuperAdmin);
        $this->postJson("/api/v1/float-transfers/{$id}/approve")->assertOk();

        expect(AuditLog::query()->where('action', AuditAction::FloatTransferRequested->value)->exists())->toBeTrue();
        expect(AuditLog::query()
            ->where('action', AuditAction::FloatTransferApproved->value)
            ->where('user_id', $approver->id)->exists())->toBeTrue();
    });
});

describe('head office resolution', function (): void {
    /**
     * The company profile names the head office; the `is_head_office` flag is
     * only the fallback for when it does not.
     *
     * This was inverted in practice. Both resolvers reached for
     * `$profile->headquartersBranch`, and the relation is called `headquarters`
     * — so the property resolved to nothing, and outside production
     * `shouldBeStrict` turned that into an exception while inside production it
     * was silently null. Either way the configured branch was never consulted
     * and the flagged one always won, which is invisible until the two disagree
     * and then routes company float and capital to the wrong branch.
     */
    it('draws company float from the branch the profile names, not the flagged one', function (): void {
        $configured = branchNamed('Kakonko');
        $flagged = Branch::query()->where('is_head_office', true)->sole();

        expect($configured->getKey())->not->toBe($flagged->getKey());

        App\Models\CompanyProfile::query()->first()
            ->forceFill(['headquarters_branch_id' => $configured->getKey()])->save();

        expect(app(App\Domain\Treasury\Services\FloatAccountResolver::class)->headOffice()->getKey())
            ->toBe($configured->getKey());
    });

    it('falls back to the flagged branch when the profile names none', function (): void {
        App\Models\CompanyProfile::query()->first()
            ->forceFill(['headquarters_branch_id' => null])->save();

        expect(app(App\Domain\Treasury\Services\FloatAccountResolver::class)->headOffice()->getKey())
            ->toBe(Branch::query()->where('is_head_office', true)->value('id'));
    });
});
