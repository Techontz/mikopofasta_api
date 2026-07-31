<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\Enums\AccountType;
use App\Enums\ActiveStatus;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\ExpenseCategory;

/**
 * Expenses → Register Branch Expenses, Headquarters Expenses → Register
 * Expenses, and Settings → Expense Categories.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

describe('listing', function (): void {
    it('returns both registers, and filters to one', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'scope' => 'branch'])->assertCreated();
        $this->postJson('/api/v1/expense-categories', ['name' => 'Stationery', 'scope' => 'headquarters'])->assertCreated();

        $this->getJson('/api/v1/expense-categories')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/expense-categories?scope=branch')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Rent');
    });

    it('serves both frontend shapes from one record', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'scope' => 'branch'])->assertCreated();

        // ExpenseNameSchema needs id/name/scope; ExpenseCategorySchema needs
        // chartAccountId/createdBy/deletedAt as well. Both are always present,
        // so neither screen needs its own endpoint.
        $this->getJson('/api/v1/expense-categories')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'scope', 'chartAccountId', 'createdBy', 'deletedAt']],
            ]);
    });

    it('reports what has been spent when asked, and not otherwise', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'scope' => 'branch'])->assertCreated();

        $this->getJson('/api/v1/expense-categories')
            ->assertOk()
            ->assertJsonMissingPath('data.0.spentToDate');

        $this->getJson('/api/v1/expense-categories?with_balances=1')
            ->assertOk()
            ->assertJsonPath('data.0.spentToDate', '0.00');
    });
});

describe('creating', function (): void {
    it('mints a 6xxx expense account the category owns', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'scope' => 'branch'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Rent');

        $category = ExpenseCategory::query()->with('chartAccount')->firstOrFail();
        $account = $category->chartAccount;

        expect($account->type)->toBe(AccountType::Expense);
        expect($account->name)->toBe('Rent Expense');
        expect($account->is_system)->toBeFalse();
        expect($account->status)->toBe(ActiveStatus::Active);

        // Dynamic, so it starts past 6000/6100 which SystemAccountCode holds.
        expect($account->code)->toStartWith('6200-');
    });

    it('does not say Expense twice when the name already does', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/expense-categories', ['name' => 'Bank Charges Expense', 'scope' => 'headquarters'])
            ->assertCreated();

        expect(ExpenseCategory::query()->firstOrFail()->chartAccount->name)->toBe('Bank Charges Expense');
    });

    it('gives each category its own account rather than sharing one', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'scope' => 'branch'])->assertCreated();
        $this->postJson('/api/v1/expense-categories', ['name' => 'Usafiri', 'scope' => 'branch'])->assertCreated();

        // ACCOUNT OVERVIEW §G: "Kila category: = Ledger yake".
        $codes = ExpenseCategory::query()->with('chartAccount')->get()
            ->map(fn (ExpenseCategory $c): string => $c->chartAccount->code);

        expect($codes->unique())->toHaveCount(2);
    });

    it('refuses a duplicate name on the same register', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'scope' => 'branch'])->assertCreated();

        $this->postJson('/api/v1/expense-categories', ['name' => 'rent', 'scope' => 'branch'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
    });

    it('allows the same name on the other register', function (): void {
        officerAt('Head Office', RoleName::Finance);

        // Head office and a branch may both keep a "Stationery"; they are
        // different budget lines.
        $this->postJson('/api/v1/expense-categories', ['name' => 'Stationery', 'scope' => 'branch'])->assertCreated();
        $this->postJson('/api/v1/expense-categories', ['name' => 'Stationery', 'scope' => 'headquarters'])->assertCreated();

        expect(ExpenseCategory::query()->count())->toBe(2);
    });

    it('records who added it', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'scope' => 'branch'])->assertCreated();

        expect(AuditLog::query()->where('action', AuditAction::ExpenseCategoryCreated->value)->exists())->toBeTrue();
    });

    it('rejects a name too short to mean anything', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/expense-categories', ['name' => 'R', 'scope' => 'branch'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Enter an expense name.');
    });
});

describe('renaming', function (): void {
    it('renames the ledger account with the category', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'scope' => 'branch'])->assertCreated();
        $category = ExpenseCategory::query()->firstOrFail();

        $this->putJson("/api/v1/expense-categories/{$category->id}", ['name' => 'Office Rent'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Office Rent');

        // Otherwise the trial balance and the register would disagree about
        // what a line is.
        expect($category->fresh()->chartAccount->name)->toBe('Office Rent Expense');
    });

    it('will not move a name between registers', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'scope' => 'branch'])->assertCreated();
        $category = ExpenseCategory::query()->firstOrFail();

        $this->putJson("/api/v1/expense-categories/{$category->id}", [
            'name' => 'Rent',
            'scope' => 'headquarters',
        ])->assertOk();

        // The scope is fixed at creation: re-filing every request under it
        // would silently change historical Branch P&L.
        expect($category->fresh()->scope->value)->toBe('branch');
    });
});

describe('retiring', function (): void {
    it('soft-deletes and takes the ledger account out of service', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'scope' => 'branch'])->assertCreated();
        $category = ExpenseCategory::query()->firstOrFail();
        $accountId = $category->chart_account_id;

        $this->deleteJson("/api/v1/expense-categories/{$category->id}")->assertOk();

        expect(ExpenseCategory::query()->withTrashed()->find($category->id)->deleted_at)->not->toBeNull();

        // Deactivated, never deleted: it holds every shilling spent under this
        // name, and LedgerService refuses to post to an inactive account — so
        // "no new requests" is true at the posting layer, not just the picker.
        expect(ChartOfAccount::query()->findOrFail($accountId)->status)->toBe(ActiveStatus::Inactive);
    });

    it('frees the name for reuse', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'scope' => 'branch'])->assertCreated();
        $category = ExpenseCategory::query()->firstOrFail();

        $this->deleteJson("/api/v1/expense-categories/{$category->id}")->assertOk();

        // The unique index keys on a live/deleted marker, not on deleted_at —
        // which under MySQL's NULL-distinct rule would have constrained nothing.
        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'scope' => 'branch'])->assertCreated();

        expect(ExpenseCategory::query()->count())->toBe(1);
        expect(ExpenseCategory::query()->withTrashed()->count())->toBe(2);
    });

    it('refuses while a request is still awaiting a decision', function (): void {
        $user = officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'scope' => 'branch'])->assertCreated();
        $category = ExpenseCategory::query()->firstOrFail();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'branchId' => $user->branch_id,
            'amount' => '5000',
            'description' => 'Office rent',
        ])->assertCreated();

        $this->deleteJson("/api/v1/expense-categories/{$category->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');
    });
});

describe('authorization', function (): void {
    it('lets a role with only treasury.view read but not register', function (): void {
        // Auditor holds treasury.view and neither treasury.manage nor
        // admin.org_settings.
        actingAsRole(RoleName::Auditor);

        $this->getJson('/api/v1/expense-categories')->assertOk();
        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'scope' => 'branch'])->assertForbidden();
    });

    it('refuses an unauthenticated caller', function (): void {
        $this->getJson('/api/v1/expense-categories')->assertUnauthorized();
    });
});
