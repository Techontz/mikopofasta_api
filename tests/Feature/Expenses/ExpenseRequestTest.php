<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\AccountResolver;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRequest;
use App\Models\JournalEntry;
use App\Models\User;

/**
 * The four expense claim screens — Expenses → All Expenses Request / All
 * Approved Expenses, and the two headquarters equivalents.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

/** Registers a category through the API and returns it. */
function expenseCategory(string $name = 'Rent', string $scope = 'branch'): ExpenseCategory
{
    test()->postJson('/api/v1/expense-categories', ['name' => $name, 'scope' => $scope])->assertCreated();

    return ExpenseCategory::query()->where('name', $name)->firstOrFail();
}

function branchNamedForExpense(string $name): Branch
{
    return Branch::query()->where('name', $name)->firstOrFail();
}

/** The cached balance of a branch's till, as a float. */
function tillBalance(Branch $branch): float
{
    return (float) app(AccountResolver::class)->tellerCash($branch)
        ->load('balances')->cachedBalance()->toDecimalString();
}

/**
 * The request just filed.
 *
 * Auto-increment is not reset between tests, so an id may not be 1 even in a
 * freshly-migrated table. Reading it back is the only reliable way to address
 * the record a test just created.
 */
function latestExpenseRequestId(): int
{
    return (int) ExpenseRequest::query()->latest('id')->firstOrFail()->getKey();
}

/**
 * A second identity that may approve.
 *
 * §14 forbids approving one's own request, so every approval test needs two
 * people. Switching the acting user mid-test requires forgetting the guard,
 * or the second request silently reuses the first identity.
 */
function switchToApprover(): User
{
    forgetAuthGuards();

    return officerAt('Head Office', RoleName::Finance);
}

describe('filing', function (): void {
    it('records a pending request and posts nothing', function (): void {
        $user = officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();
        $entriesBefore = JournalEntry::query()->count();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'branchId' => $user->branch_id,
            'amount' => '25000',
            'description' => 'Nimelipa bill ya maji ofisini',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '25000.00')
            ->assertJsonPath('data.journalEntryId', null);

        // A queue of requests must never touch the trial balance.
        expect(JournalEntry::query()->count())->toBe($entriesBefore);
    });

    it('numbers requests EXP-0000001 upward', function (): void {
        $user = officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        foreach (['1000', '2000'] as $amount) {
            $this->postJson('/api/v1/expense-requests', [
                'expenseCategoryId' => $category->id,
                'branchId' => $user->branch_id,
                'amount' => $amount,
                'description' => 'Office costs',
            ])->assertCreated();
        }

        expect(ExpenseRequest::query()->orderBy('id')->pluck('reference')->all())
            ->toBe(['EXP-0000001', 'EXP-0000002']);
    });

    it('falls back to the requester own branch', function (): void {
        $user = officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        // A branch officer filing for their own branch should not have to say so.
        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '5000',
            'description' => 'Office electricity',
        ])
            ->assertCreated()
            ->assertJsonPath('data.branch', 'Kakonko');

        expect(ExpenseRequest::query()->firstOrFail()->branch_id)->toBe($user->branch_id);
    });

    it('books a headquarters request to head office whatever branch was sent', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory('Stationery', 'headquarters');

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            // Deliberately a different branch: the register decides, not the form.
            'branchId' => branchNamedForExpense('Kakonko')->id,
            'amount' => '15000',
            'description' => 'Printer paper',
        ])
            ->assertCreated()
            ->assertJsonPath('data.scope', 'headquarters')
            ->assertJsonPath('data.branch', 'Head Office');
    });

    it('refuses a category from the other register when the caller states one', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $hq = expenseCategory('Stationery', 'headquarters');

        // The branch screen sends scope=branch. Refused rather than quietly
        // filed as a headquarters cost — the Expense Tagging Report exists to
        // find exactly this, and should never have to find one we created.
        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $hq->id,
            'scope' => 'branch',
            'amount' => '15000',
            'description' => 'Printer paper',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
    });

    it('refuses a retired category', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();
        $this->deleteJson("/api/v1/expense-categories/{$category->id}")->assertOk();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '5000',
            'description' => 'Office rent',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.expenseCategoryId.0', 'Choose an expense.');
    });

    it('refuses an expense dated in the future', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '5000',
            'description' => 'Office rent',
            'requestedOn' => now()->addWeek()->toDateString(),
        ])->assertStatus(422);
    });

    it('refuses a zero amount', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '0',
            'description' => 'Office rent',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount.0', 'Enter an amount greater than zero.');
    });
});

describe('approval', function (): void {
    it('posts Dr expense Cr branch till and draws the till down', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();
        $kakonko = branchNamedForExpense('Kakonko');

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'branchId' => $kakonko->id,
            'amount' => '25000',
            'description' => 'Office rent',
        ])->assertCreated();

        $request = ExpenseRequest::query()->firstOrFail();
        $before = tillBalance($kakonko);

        $id = latestExpenseRequestId();
        switchToApprover();

        $this->postJson("/api/v1/expense-requests/{$request->id}/decide", [
            'decision' => 'approved',
            'comment' => 'Within the monthly budget.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        expect($before - tillBalance($kakonko))->toBe(25000.0);

        $entry = JournalEntry::query()->with('lines')->latest('id')->firstOrFail();
        expect($entry->source_type)->toBe(JournalSourceType::Expense);
        expect($entry->lines)->toHaveCount(2);

        $debit = $entry->lines->firstWhere(fn ($l): bool => (float) $l->debit_amount > 0);
        expect($debit->account_id)->toBe($category->chart_account_id);

        // The branch dimension is what makes Branch P&L a filtered query.
        expect($entry->lines->every(fn ($l): bool => $l->branch_id === $kakonko->id))->toBeTrue();
    });

    it('posts a balanced entry', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '25000',
            'description' => 'Office rent',
        ])->assertCreated();

        $id = latestExpenseRequestId();
        switchToApprover();
        $this->postJson("/api/v1/expense-requests/{$id}/decide", ['decision' => 'approved'])->assertOk();

        $entry = JournalEntry::query()->with('lines')->latest('id')->firstOrFail();
        expect($entry->lines->sum(fn ($l) => (float) $l->debit_amount))
            ->toBe($entry->lines->sum(fn ($l) => (float) $l->credit_amount));
    });

    it('dates the entry when the cost was incurred, not when it was approved', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '25000',
            'description' => 'Office rent',
            'requestedOn' => '2026-03-14',
        ])->assertCreated();

        $id = latestExpenseRequestId();
        switchToApprover();
        $this->postJson("/api/v1/expense-requests/{$id}/decide", ['decision' => 'approved'])->assertOk();

        // Otherwise a receipt filed late would land in the wrong month's P&L.
        expect(JournalEntry::query()->latest('id')->firstOrFail()->entry_date->toDateString())
            ->toBe('2026-03-14');
    });

    it('posts nothing when rejected', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '9000',
            'description' => 'Refreshments',
        ])->assertCreated();

        $entriesBefore = JournalEntry::query()->count();
        $id = latestExpenseRequestId();
        switchToApprover();

        $this->postJson("/api/v1/expense-requests/{$id}/decide", [
            'decision' => 'rejected',
            'comment' => 'Not a business cost.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.comment', 'Not a business cost.')
            ->assertJsonPath('data.journalEntryId', null);

        expect(JournalEntry::query()->count())->toBe($entriesBefore);
    });

    it('refuses to decide the same request twice', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '25000',
            'description' => 'Office rent',
        ])->assertCreated();

        $id = latestExpenseRequestId();
        switchToApprover();
        $this->postJson("/api/v1/expense-requests/{$id}/decide", ['decision' => 'approved'])->assertOk();

        // Re-approving would post the cost a second time.
        $this->postJson("/api/v1/expense-requests/{$id}/decide", ['decision' => 'approved'])
            ->assertStatus(409);

        expect(JournalEntry::query()->where('source_type', JournalSourceType::Expense)->count())->toBe(1);
    });

    it('will not let the requester approve their own request', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '25000',
            'description' => 'Office rent',
        ])->assertCreated();

        // §14 separation of duties — the same rule loan approval follows.
        $id = latestExpenseRequestId();
        $this->postJson("/api/v1/expense-requests/{$id}/decide", ['decision' => 'approved'])
            ->assertForbidden();
    });

    it('records the decision in the audit trail', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '25000',
            'description' => 'Office rent',
        ])->assertCreated();

        $id = latestExpenseRequestId();
        switchToApprover();
        $this->postJson("/api/v1/expense-requests/{$id}/decide", ['decision' => 'approved'])->assertOk();

        expect(AuditLog::query()->where('action', AuditAction::ExpenseApproved->value)->exists())->toBeTrue();
    });

    it('refuses anything other than approved or rejected', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '25000',
            'description' => 'Office rent',
        ])->assertCreated();

        $id = latestExpenseRequestId();
        switchToApprover();
        $this->postJson("/api/v1/expense-requests/{$id}/decide", ['decision' => 'pending'])
            ->assertStatus(422);
    });
});

describe('listing', function (): void {
    it('filters by register, status and branch, and totals what it shows', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $branchCategory = expenseCategory();
        $hqCategory = expenseCategory('Stationery', 'headquarters');

        foreach ([['branch', $branchCategory->id, '50000'], ['branch', $branchCategory->id, '25000']] as [, $id, $amount]) {
            $this->postJson('/api/v1/expense-requests', [
                'expenseCategoryId' => $id,
                'amount' => $amount,
                'description' => 'Office costs',
            ])->assertCreated();
        }

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $hqCategory->id,
            'amount' => '15000',
            'description' => 'Printer paper',
        ])->assertCreated();

        $this->getJson('/api/v1/expense-requests')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', '90000.00');

        $this->getJson('/api/v1/expense-requests?scope=branch')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', '75000.00');

        $this->getJson('/api/v1/expense-requests?scope=headquarters')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('separates what is claimed from what has actually been spent', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        foreach (['50000', '25000'] as $amount) {
            $this->postJson('/api/v1/expense-requests', [
                'expenseCategoryId' => $category->id,
                'amount' => $amount,
                'description' => 'Office costs',
            ])->assertCreated();
        }

        $id = latestExpenseRequestId();
        switchToApprover();
        $this->postJson("/api/v1/expense-requests/{$id}/decide", ['decision' => 'approved'])->assertOk();

        // The difference between the two is the value of the queue still
        // waiting for a decision — and approvedTotal is what ties to the ledger.
        $this->getJson('/api/v1/expense-requests')
            ->assertOk()
            ->assertJsonPath('meta.total', '75000.00')
            ->assertJsonPath('meta.approvedTotal', '25000.00');
    });

    it('returns the flat names the frontend schema declares', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '25000',
            'description' => 'Office rent',
        ])->assertCreated();

        // ExpenseClaimSchema declares branch/staff/expense as plain strings.
        $this->getJson('/api/v1/expense-requests')
            ->assertOk()
            ->assertJsonPath('data.0.branch', 'Kakonko')
            ->assertJsonPath('data.0.expense', 'Rent')
            ->assertJsonStructure(['data' => [['id', 'scope', 'branch', 'staff', 'expense', 'amount', 'description', 'comment', 'status', 'date']]]);
    });
});

describe('comment', function (): void {
    it('saves a comment and keeps the earlier text in the audit trail', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '25000',
            'description' => 'Office rent',
            'comment' => 'First note',
        ])->assertCreated();

        $id = latestExpenseRequestId();
        $this->patchJson("/api/v1/expense-requests/{$id}/comment", ['comment' => 'Second note'])
            ->assertOk()
            ->assertJsonPath('data.comment', 'Second note');

        $log = AuditLog::query()->where('action', AuditAction::ExpenseCommented->value)->firstOrFail();
        expect($log->before_json['comment'])->toBe('First note');
    });
});

describe('withdrawing', function (): void {
    it('removes a pending request', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '25000',
            'description' => 'Office rent',
        ])->assertCreated();

        $id = latestExpenseRequestId();
        $this->deleteJson("/api/v1/expense-requests/{$id}")->assertOk();

        expect(ExpenseRequest::query()->count())->toBe(0);
        expect(ExpenseRequest::query()->withTrashed()->count())->toBe(1);
    });

    it('refuses once it has posted', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '25000',
            'description' => 'Office rent',
        ])->assertCreated();

        $id = latestExpenseRequestId();
        switchToApprover();
        $this->postJson("/api/v1/expense-requests/{$id}/decide", ['decision' => 'approved'])->assertOk();

        // §2 no-delete: the only way back from a posting is a reversal.
        $this->deleteJson("/api/v1/expense-requests/{$id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');
    });
});

describe('authorization', function (): void {
    it('refuses an unauthenticated caller', function (): void {
        $this->getJson('/api/v1/expense-requests')->assertUnauthorized();
    });

    it('denies a role without treasury.view', function (): void {
        actingAsRole(RoleName::LoanOfficer);

        $this->getJson('/api/v1/expense-requests')->assertForbidden();
    });
});

describe('paid from a bank account', function (): void {
    it('credits the bank account rather than the branch till', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory('Bank Charges', 'headquarters');

        $this->postJson('/api/v1/bank-accounts', [
            'bankName' => 'CRDB Bank',
            'accountName' => 'Mikopofasta Operations',
            'accountNumber' => '0150999888777',
            'currency' => 'TZS',
            'openingBalance' => '500000',
            'status' => 'active',
        ])->assertCreated();

        $bank = App\Models\BankAccount::query()->where('account_number', '0150999888777')->firstOrFail();
        $kakonko = branchNamedForExpense('Kakonko');
        $tillBefore = tillBalance($kakonko);

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'bankAccountId' => $bank->id,
            'amount' => '15000',
            'description' => 'Monthly account fee',
        ])->assertCreated();

        $id = latestExpenseRequestId();
        switchToApprover();
        $this->postJson("/api/v1/expense-requests/{$id}/decide", ['decision' => 'approved'])->assertOk();

        // Same record, same approval, same debit — only the credit side moves.
        $bankBalance = (float) $bank->fresh()->load('chartAccount.balances')
            ->currentBalance()->toDecimalString();

        expect($bankBalance)->toBe(485000.0);
        expect(tillBalance($kakonko))->toBe($tillBefore);
    });

    it('still credits the till when no bank account is named', function (): void {
        officerAt('Kakonko', RoleName::Finance);
        $category = expenseCategory();
        $kakonko = branchNamedForExpense('Kakonko');
        $tillBefore = tillBalance($kakonko);

        $this->postJson('/api/v1/expense-requests', [
            'expenseCategoryId' => $category->id,
            'amount' => '5000',
            'description' => 'Office rent',
        ])->assertCreated();

        $id = latestExpenseRequestId();
        switchToApprover();
        $this->postJson("/api/v1/expense-requests/{$id}/decide", ['decision' => 'approved'])->assertOk();

        expect($tillBefore - tillBalance($kakonko))->toBe(5000.0);
    });
});
