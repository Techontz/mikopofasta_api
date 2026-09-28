<?php

namespace Tests\Feature\Api\Capital;

use App\Enums\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\FloatTransfer;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class FloatApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private function ledger(): Ledger
    {
        return app(Ledger::class);
    }

    /**
     * The company funds HQ only: Company A/C, a bank account or the Investment RESERVE A/C → the HQ PRINCIPAL A/C. A branch is
     * never a float destination (HQ/Finance funds and disburses loans) and an asset is never a source.
     */
    public function test_company_float_moves_a_chosen_source_to_the_hq_principal_account(): void
    {
        $admin = $this->signInAdmin();
        $this->ledger()->openingBalance($admin->company_id, Account::Company, 1000000, 'CAPITAL');

        // Rule 6: the float is requested (pending, nothing posted) and approved by another authorised user.
        $id = $this->postJson('/api/v1/capital/floats', ['amount' => 400000, 'from_account' => Account::Company->value])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertSame(0.0, $this->ledger()->balance($admin->company_id, Account::Principal));
        $this->assertSame(1000000.0, $this->ledger()->balance($admin->company_id, Account::Company), 'a pending float moves nothing');

        $this->asApprover($admin, fn () => $this->postJson("/api/v1/capital/floats/{$id}/approve")->assertOk()->assertJsonPath('message', 'Float Approved successfully'));

        $this->assertSame(600000.0, $this->ledger()->balance($admin->company_id, Account::Company));
        $this->assertSame(400000.0, $this->ledger()->balance($admin->company_id, Account::Principal), 'HQ PRINCIPAL A/C, no branch');
        $this->assertSame(0.0, $this->ledger()->balance($admin->company_id, Account::Principal, $admin->branch_id));

        $this->getJson('/api/v1/capital/floats')->assertOk()
            ->assertJsonPath('total', 400000)
            ->assertJsonPath('data.0.to_account', 'PRINCIPAL A/C')
            ->assertJsonPath('data.0.to_branch', null)
            ->assertJsonPath('sources.0.label', 'Company A/C')
            ->assertJsonPath('sources.0.balance', 600000);
        $this->getJson('/api/v1/capital/floats?from=2000-01-01&to=2000-01-02')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/capital/floats/approved')->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.to_account', 'PRINCIPAL A/C')
            ->assertJsonPath('total', 400000);

        // Only posted floats count towards the total; a second pending request is listed separately.
        $this->postJson('/api/v1/capital/floats', ['amount' => 50000, 'from_account' => Account::Company->value])->assertCreated();
        $this->getJson('/api/v1/capital/floats')->assertOk()->assertJsonPath('total', 400000)->assertJsonPath('total_pending', 50000);
        $this->getJson('/api/v1/capital/floats/approved')->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * A float is deleted only while it is pending: once approved it is posted and can be reversed, never removed. The
     * rows carry a sending branch, so the legacy branch → branch floats (which can no longer be created) are used here.
     */
    public function test_a_pending_float_is_deleted_but_an_approved_one_is_not(): void
    {
        $admin = $this->signInAdmin();
        $kakonko = Branch::factory()->create(['company_id' => $admin->company_id, 'name' => 'Kakonko']);
        $this->ledger()->openingBalance($admin->company_id, Account::Principal, 250000, branch: $admin->branch_id);

        $posted = $this->legacyBranchFloat($admin->company_id, $admin->branch_id, $kakonko->id, 100000);
        $this->asApprover($admin, fn () => $this->postJson("/api/v1/capital/floats/{$posted->id}/approve")->assertOk()->assertJsonPath('message', 'Float Approved successfully'));
        $this->asApprover($admin, fn () => $this->postJson("/api/v1/capital/floats/{$posted->id}/approve")->assertUnprocessable()->assertJsonValidationErrors('transfer'));
        $this->deleteJson("/api/v1/capital/floats/{$posted->id}")->assertUnprocessable()->assertJsonPath('message', 'Approved transaction cannot be deleted');

        $pending = $this->legacyBranchFloat($admin->company_id, $kakonko->id, $admin->branch_id, 10);
        $this->deleteJson("/api/v1/capital/floats/{$pending->id}")->assertOk()->assertJsonPath('message', 'Transaction Deleted successfully');
        $this->assertModelMissing($pending);
        $this->assertSame(150000.0, $this->ledger()->balance($admin->company_id, Account::Principal, $admin->branch_id), 'deleting a pending float moves nothing');

        // A company → HQ float has no branch at either end, so deleting it must not ask for branch access.
        $this->ledger()->openingBalance($admin->company_id, Account::Company, 500000, 'CAPITAL');
        $companyFloat = $this->postJson('/api/v1/capital/floats', ['amount' => 400000, 'from_account' => Account::Company->value])->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/capital/floats/{$companyFloat}")->assertOk();
        $this->assertSame(500000.0, $this->ledger()->balance($admin->company_id, Account::Company));
    }

    public function test_company_float_sources_are_bank_and_investment_reserve_but_never_an_asset_or_more_than_the_balance(): void
    {
        $admin = $this->signInAdmin();
        $bank = BankAccount::create(['company_id' => $admin->company_id, 'name' => 'NMB']);
        $this->ledger()->openingBalance($admin->company_id, Account::Bank, 250000, bankAccount: $bank);
        $this->ledger()->openingBalance($admin->company_id, Account::InvestmentReserve, 80000, 'RESERVE SENT TO INVESTMENT');

        $this->postJson('/api/v1/capital/floats', ['amount' => 1000, 'from_account' => Account::MotorVehicles->value])->assertUnprocessable()->assertJsonValidationErrors('from_account');
        $this->postJson('/api/v1/capital/floats', ['amount' => 1000, 'from_account' => Account::Bank->value])->assertUnprocessable()->assertJsonValidationErrors('bank_account_id');
        $this->postJson('/api/v1/capital/floats', ['amount' => 250001, 'from_account' => Account::Bank->value, 'bank_account_id' => $bank->id])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $fromBank = $this->postJson('/api/v1/capital/floats', ['amount' => 150000, 'from_account' => Account::Bank->value, 'bank_account_id' => $bank->id])->assertCreated()->json('data.id');
        $fromReserve = $this->postJson('/api/v1/capital/floats', ['amount' => 80000, 'from_account' => Account::InvestmentReserve->value])->assertCreated()->json('data.id');
        $this->asApprover($admin, function () use ($fromBank, $fromReserve): void {
            $this->postJson("/api/v1/capital/floats/{$fromBank}/approve")->assertOk();
            $this->postJson("/api/v1/capital/floats/{$fromReserve}/approve")->assertOk();
        });

        $this->assertSame(100000.0, $this->ledger()->balance($admin->company_id, Account::Bank, bankAccount: $bank));
        $this->assertSame(0.0, $this->ledger()->balance($admin->company_id, Account::InvestmentReserve));
        $this->assertSame(230000.0, $this->ledger()->balance($admin->company_id, Account::Principal));

        // The source balance is checked again at approval, so a float the source can no longer cover is not posted.
        $drained = $this->postJson('/api/v1/capital/floats', ['amount' => 100000, 'from_account' => Account::Bank->value, 'bank_account_id' => $bank->id])->assertCreated()->json('data.id');
        $this->ledger()->transfer($admin->company_id, ['account' => Account::Bank, 'bank' => $bank->id], ['account' => Account::Company], 100000, 'BANK TO COMPANY');
        $this->asApprover($admin, fn () => $this->postJson("/api/v1/capital/floats/{$drained}/approve")->assertUnprocessable()->assertJsonValidationErrors('transfer'));
        $this->assertSame(230000.0, $this->ledger()->balance($admin->company_id, Account::Principal));
    }

    public function test_permission_scope_and_isolation(): void
    {
        $admin = $this->signInAdmin();
        $otherCompanyTransfer = FloatTransfer::create(['company_id' => Company::factory()->create()->id, 'type' => 'company_to_hq', 'amount' => 1, 'status' => 'pending', 'transfer_date' => today()]);
        $this->postJson("/api/v1/capital/floats/{$otherCompanyTransfer->id}/approve")->assertNotFound();
        $this->postJson('/api/v1/capital/floats', ['amount' => 1, 'from_account' => Account::Principal->value])->assertUnprocessable()->assertJsonValidationErrors('from_account');

        $roles = $admin->company->roles()->pluck('id', 'key');
        $teller = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $roles['teller']]);
        $this->actingAs($teller)->getJson('/api/v1/capital/floats')->assertForbidden();

        $this->actingAs($admin);
        $this->ledger()->openingBalance($admin->company_id, Account::Company, 100000, 'CAPITAL');
        $this->postJson('/api/v1/capital/floats', ['amount' => 5000, 'from_account' => Account::Company->value])->assertCreated();

        // Branch/zone-scoped roles see only floats touching their branches, and a company → HQ float touches none.
        $branchA = Branch::factory()->create(['company_id' => $admin->company_id]);
        $branchB = Branch::factory()->create(['company_id' => $admin->company_id]);
        $foreignBranches = FloatTransfer::create(['company_id' => $admin->company_id, 'type' => 'branch_to_branch', 'from_branch_id' => $branchA->id, 'to_branch_id' => $branchB->id, 'from_account' => Account::Principal->value, 'to_account' => Account::Principal->value, 'amount' => 5, 'status' => 'pending', 'transfer_date' => today()]);
        $manager = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $roles['branch_manager']]);
        $manager->role->permissions()->create(['permission' => 'float.manage']);

        $this->actingAs($manager->fresh())->getJson('/api/v1/capital/floats')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($manager->fresh())->postJson("/api/v1/capital/floats/{$foreignBranches->id}/approve")->assertForbidden();
    }

    /**
     * Only Super Admin and Admin request, approve or reject floats; Finance (which holds float.manage) only views them.
     */
    public function test_only_super_admin_and_admin_can_transfer_float(): void
    {
        $superAdmin = $this->signInAdmin();
        $this->ledger()->openingBalance($superAdmin->company_id, Account::Company, 100000, 'CAPITAL');
        $roles = $superAdmin->company->roles()->pluck('id', 'key');
        $admin = Employee::factory()->create(['company_id' => $superAdmin->company_id, 'branch_id' => $superAdmin->branch_id, 'role_id' => $roles['admin']]);
        $finance = Employee::factory()->create(['company_id' => $superAdmin->company_id, 'branch_id' => $superAdmin->branch_id, 'role_id' => $roles['finance']]);

        $this->actingAs($finance)->getJson('/api/v1/capital/floats')->assertOk()->assertJsonPath('can_transfer', false);
        $this->actingAs($finance)->postJson('/api/v1/capital/floats', ['amount' => 1000, 'from_account' => Account::Company->value])
            ->assertForbidden()->assertJsonPath('message', 'Only Super Admin or Admin can transfer float.');

        $id = $this->actingAs($admin)->postJson('/api/v1/capital/floats', ['amount' => 1000, 'from_account' => Account::Company->value])->assertCreated()->json('data.id');
        $this->actingAs($finance)->getJson('/api/v1/capital/floats')->assertOk()->assertJsonPath('data.0.can_approve', false)->assertJsonPath('data.0.can_reject', false);
        $this->actingAs($finance)->postJson("/api/v1/capital/floats/{$id}/approve")->assertForbidden();
        $this->actingAs($finance)->postJson("/api/v1/capital/floats/{$id}/reject", ['reason' => 'Not needed'])->assertForbidden();

        $this->actingAs($superAdmin)->getJson('/api/v1/capital/floats')->assertOk()->assertJsonPath('can_transfer', true);
        $this->actingAs($superAdmin)->postJson("/api/v1/capital/floats/{$id}/approve")->assertOk();
        $this->assertSame(1000.0, $this->ledger()->balance($superAdmin->company_id, Account::Principal));
    }

    /**
     * A pending branch → branch float row. The flow is gone (branches hold no lending money), but the historic rows are
     * still listed, approved, rejected and reversed, so they are created straight on the model.
     */
    private function legacyBranchFloat(int $companyId, int $fromBranchId, int $toBranchId, float $amount): FloatTransfer
    {
        return FloatTransfer::create([
            'company_id' => $companyId,
            'type' => 'branch_to_branch',
            'from_branch_id' => $fromBranchId,
            'to_branch_id' => $toBranchId,
            'from_account' => Account::Principal->value,
            'to_account' => Account::Principal->value,
            'amount' => $amount,
            'status' => 'pending',
            'transfer_date' => today(),
        ]);
    }
}
