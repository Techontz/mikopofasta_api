<?php

namespace Tests\Feature\Api\SalaryAdvance;

use App\Enums\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\ReversalRequest;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceCategory;
use App\Models\SalaryAdvancePayment;
use App\Services\Ledger;
use App\Services\PeriodClose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class SalaryAdvanceApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    private SalaryAdvanceCategory $category;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->category = SalaryAdvanceCategory::create([
            'company_id' => $this->admin->company_id,
            'name' => 'WATUMISHI',
            'interest_rate' => 20,
            'amount_from' => 10000,
            'amount_to' => 30000,
            'fee' => 200,
        ]);
        $this->customer = Customer::factory()->create(['branch_id' => $this->admin->branch_id, 'work_status' => 'ent']);
    }

    public function test_category_crud(): void
    {
        $this->getJson('/api/v1/salary-advance/categories')->assertOk()->assertJsonPath('data.0.name', 'WATUMISHI')->assertJsonPath('data.0.interest_rate', 20);

        $id = $this->postJson('/api/v1/salary-advance/categories', [
            'perferal_name' => 'WAJASIRIAMALI', 'interest_name' => 15, 'from_amount' => 5000, 'to_amount' => 50000, 'fee_charger' => 500,
        ])->assertCreated()->assertJsonPath('message', 'Salary advance Category Registered successfully')->json('data.id');

        $this->putJson("/api/v1/salary-advance/categories/{$id}", [
            'perferal_name' => 'WAJASIRIAMALI', 'interest_name' => 25, 'from_amount' => 5000, 'to_amount' => 60000, 'fee_charger' => 500,
        ])->assertOk()->assertJsonPath('data.interest_rate', 25);

        $this->deleteJson("/api/v1/salary-advance/categories/{$id}")->assertOk()->assertJsonPath('message', 'Salary advance Category Deleted successfully');
        $this->assertDatabaseMissing('salary_advance_categories', ['id' => $id]);
    }

    public function test_category_validation_and_protected_delete(): void
    {
        $this->postJson('/api/v1/salary-advance/categories', ['perferal_name' => '', 'from_amount' => 5000, 'to_amount' => 100])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['perferal_name', 'interest_name', 'to_amount', 'fee_charger']);

        $this->pendingAdvance();
        $this->deleteJson("/api/v1/salary-advance/categories/{$this->category->id}")->assertUnprocessable();
    }

    public function test_request_creates_pending_advance_with_interest_and_fee(): void
    {
        $this->postJson('/api/v1/salary-advance/advances', [
            'blanch_id' => $this->admin->branch_id,
            'customer_id' => $this->customer->id,
            'per_id' => $this->category->id,
            'loan_amount' => 20000,
        ])->assertCreated()->assertJsonPath('message', 'Salary Advance Requested successfully');

        $this->assertDatabaseHas('salary_advances', ['customer_id' => $this->customer->id, 'amount' => 20000, 'total_payable' => 24000, 'fee' => 200, 'status' => 'pending', 'employee_id' => $this->admin->id]);

        $this->getJson('/api/v1/salary-advance/requested')
            ->assertOk()
            ->assertJsonPath('data.0.total_payable', 24000)
            ->assertJsonPath('data.0.remaining_amount', 24000)
            ->assertJsonPath('data.0.customer', $this->customer->full_name);
    }

    public function test_request_validation_and_category_range(): void
    {
        $this->postJson('/api/v1/salary-advance/advances', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['blanch_id' => 'Please select branch', 'customer_id', 'per_id', 'loan_amount']);

        $this->postJson('/api/v1/salary-advance/advances', [
            'blanch_id' => $this->admin->branch_id, 'customer_id' => $this->customer->id, 'per_id' => $this->category->id, 'loan_amount' => 90000,
        ])->assertUnprocessable()->assertJsonValidationErrors(['loan_amount' => 'Loan amount must be between 10,000 - 30,000']);

        $this->assertDatabaseCount('salary_advances', 0);
    }

    public function test_approve_repay_and_paid_list_post_ledger_entries(): void
    {
        $advance = $this->pendingAdvance();
        $ledger = app(Ledger::class);
        $company = $this->admin->company_id;
        $branch = $this->admin->branch_id;

        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/approve")->assertOk()->assertJsonPath('message', 'Salary Advance Approved successfully');
        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/approve")->assertUnprocessable();

        $this->assertSame('active', $advance->fresh()->status);
        $this->assertEquals(-20000, $ledger->balance($company, Account::Principal), 'an advance is lent out of OPERATION PRINCIPAL');
        $this->assertEquals(0, $ledger->balance($company, Account::HqSalaryAdvance), 'the HQ salary advance pot is not the source');
        $this->assertEquals(20000, $ledger->balance($company, Account::SalaryAdvanceReceivable, $branch));
        $this->assertEquals(0, $ledger->balance($company, Account::LoanFee, $branch), 'C2: no fee at approval');
        $this->assertEquals(0, $ledger->balance($company, Account::FeeIncome, $branch), 'C2: no fee income at approval');

        $this->getJson('/api/v1/salary-advance/approved')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/salary-advance/active')->assertOk()->assertJsonPath('data.0.id', $advance->id)->assertJsonPath('data.0.alert', 'new');

        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/payments", ['amount' => 30000])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount' => 'Amount is greater than remain amount (24,000)']);

        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/payments", ['amount' => 10000])->assertOk()->assertJsonPath('message', 'Deposit successfully');
        $this->assertEquals(10000, $ledger->balance($company, Account::SalaryAdvanceReceivable, $branch), 'Principal is recovered first');
        $this->assertEquals(0, $ledger->balance($company, Account::InterestIncome, $branch));

        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/payments", ['amount' => 14000])->assertOk();
        $this->assertSame('done', $advance->fresh()->status);
        $this->assertEquals(0, $ledger->balance($company, Account::Principal), 'OPERATION PRINCIPAL regains the principal, and only the principal');
        $this->assertEquals(4000, $ledger->balance($company, Account::HqInterest), 'Salary advance interest lands in the interest pool (spec §8)');
        $this->assertEquals(0, $ledger->balance($company, Account::SalaryAdvanceReceivable, $branch));
        // §9: salary advance profit is its own income category — not interest income, and no 20% reserve on it.
        $this->assertEquals(4000, $ledger->balance($company, Account::SalaryAdvanceIncome, $branch));
        $this->assertEquals(0, $ledger->balance($company, Account::InterestIncome, $branch));
        $this->assertEquals(0, $ledger->balance($company, Account::InterestReserve, $branch));
        $pnl = app(PeriodClose::class)->companyResult($company, today());
        $this->assertEquals([4000, 0, 4000], [$pnl['salary_advance_income'], $pnl['interest_income'], $pnl['total_income']]);

        $this->getJson('/api/v1/salary-advance/paid')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.amount', 14000);
        $this->getJson('/api/v1/salary-advance/paid?branch_id=all&from=2000-01-01&to=2000-01-02')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/salary-advance/repayments')->assertOk()->assertJsonPath('data.0.status', 'done')->assertJsonCount(2, 'data.0.payments');
        $this->getJson('/api/v1/salary-advance/active')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_deposit_reversal_is_requested_then_approved_by_another_user_and_the_amount_is_owed_again(): void
    {
        $advance = $this->pendingAdvance();
        $ledger = app(Ledger::class);
        [$company, $branch] = [$this->admin->company_id, $this->admin->branch_id];
        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/approve")->assertOk();
        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/payments", ['amount' => 10000])->assertOk();
        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/payments", ['amount' => 14000])->assertOk();
        [$first, $last] = SalaryAdvancePayment::orderBy('id')->get()->all();
        $this->assertSame('done', $advance->fresh()->status);
        $entries = JournalEntry::count();

        $history = $this->getJson('/api/v1/salary-advance/repayments')->assertOk()->json('data.0.payments');
        $this->assertSame([false, false], array_column($history, 'can_request_reversal'));
        $this->assertSame(['Reverse the newest deposit first.', 'You recorded this deposit; another user must request its reversal.'], array_column($history, 'reversal_blocked_reason'));

        $maker = $this->secondApprover($this->admin, 'finance');
        $this->actingAs($maker)->postJson("/api/v1/salary-advance/payments/{$first->id}/reverse", ['reason' => 'Wrong amount'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'A later deposit exists on this salary advance; reverse the newest deposit first.']);
        $this->postJson("/api/v1/salary-advance/payments/{$last->id}/reverse", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/v1/salary-advance/payments/{$last->id}/reverse", ['reason' => 'Posted twice'])->assertCreated();

        $request = ReversalRequest::sole();
        $this->assertSame([ReversalRequest::SALARY_ADVANCE_PAYMENT, 14000.0, $branch], [$request->type, (float) $request->amount, $request->branch_id]);
        $this->assertNull($last->fresh()->reversed_at, 'Nothing changes until the request is approved.');
        $this->assertSame($entries, JournalEntry::count());
        $this->getJson('/api/v1/salary-advance/repayments')->assertOk()->assertJsonPath('data.0.payments.1.reversal_pending', true);
        $this->getJson('/api/v1/reversal-requests')->assertOk()
            ->assertJsonPath('data.0.type_label', 'Salary Advance Deposit')
            ->assertJsonPath('data.0.customer', $this->customer->full_name)
            ->assertJsonPath('data.0.can_approve', false);

        $this->actingAs($this->admin)->postJson("/api/v1/reversal-requests/{$request->id}/approve")->assertForbidden();
        $this->asApprover($this->admin, fn () => $this->postJson("/api/v1/reversal-requests/{$request->id}/approve")->assertOk()
            ->assertJsonPath('message', 'Salary advance deposit reversed successfully. TZS 14,000 is owed on the salary advance again.'));

        $last->refresh();
        $this->assertNotNull($last->reversed_at);
        $this->assertSame('Posted twice', $last->reversal_reason);
        $this->assertSame($last->reversal_journal_entry_id, $request->fresh()->reversal_journal_entry_id);
        $this->assertSame('active', $advance->fresh()->status, 'The advance the deposit had finished is active again.');
        $this->assertEquals(14000, $advance->fresh()->remaining_amount);
        $this->assertEquals(10000, $ledger->balance($company, Account::SalaryAdvanceReceivable, $branch), 'Back to after the first deposit.');
        $this->assertEquals(0, $ledger->balance($company, Account::SalaryAdvanceIncome, $branch));
        $this->assertEquals(-10000, $ledger->balance($company, Account::Principal));

        $this->getJson('/api/v1/salary-advance/active')->assertOk()
            ->assertJsonPath('data.0.paid_amount', 10000)
            ->assertJsonPath('data.0.payments.1.reversed', true)
            ->assertJsonPath('data.0.payments.0.can_request_reversal', false);
        $this->getJson('/api/v1/salary-advance/paid')->assertOk()->assertJsonCount(1, 'data');

        // The first deposit is now the newest standing one and can be reversed in turn; a new deposit can be taken again.
        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/payments", ['amount' => 14000])->assertOk();
        $this->assertSame('done', $advance->fresh()->status);
    }

    public function test_pending_request_is_deleted_without_ledger_effect(): void
    {
        $advance = $this->pendingAdvance();

        $this->deleteJson("/api/v1/salary-advance/advances/{$advance->id}")->assertOk()->assertJsonPath('message', 'Salary Advance Deleted successfully');

        $this->assertModelMissing($advance);
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_approved_advance_is_reversed_not_deleted(): void
    {
        $advance = $this->pendingAdvance();
        $ledger = app(Ledger::class);
        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/approve")->assertOk();
        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/payments", ['amount' => 5000])->assertOk();

        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/collect-fee", ['method' => 'CASH'])->assertOk();
        $this->assertEquals(200, $ledger->balance($this->admin->company_id, Account::FeeIncome, $this->admin->branch_id));

        $this->deleteJson("/api/v1/salary-advance/advances/{$advance->id}")->assertUnprocessable()->assertJsonValidationErrors('reason');
        // Rule 6: the employee who approved (posted) the advance does not reverse it.
        $this->deleteJson("/api/v1/salary-advance/advances/{$advance->id}", ['reason' => 'Wrong customer'])->assertForbidden();
        $this->asApprover($this->admin, fn () => $this->deleteJson("/api/v1/salary-advance/advances/{$advance->id}", ['reason' => 'Wrong customer'])->assertOk()->assertJsonPath('message', 'Salary Advance Reversed successfully'));

        $advance->refresh();
        $this->assertSame('reversed', $advance->status);
        $this->assertNotNull($advance->reversed_at);
        $this->assertSame(3, JournalEntry::whereNotNull('reversal_of_id')->count(), 'approval, repayment and the collected fee are mirrored');
        $this->assertEquals(0, $ledger->balance($this->admin->company_id, Account::Principal));
        $this->assertEquals(0, $ledger->balance($this->admin->company_id, Account::SalaryAdvanceReceivable, $this->admin->branch_id));
        $this->assertEquals(0, $ledger->balance($this->admin->company_id, Account::FeeIncome, $this->admin->branch_id));
        $this->assertEquals(0, $ledger->balance($this->admin->company_id, Account::LoanFee, $this->admin->branch_id));
        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/collect-fee")->assertUnprocessable()->assertJsonPath('errors.fee.0', 'Salary advance is reversed; its fee cannot be collected.');
        $this->assertTrue(AuditLog::where('action', 'SalaryAdvance.updated')->where('auditable_id', $advance->id)->exists());
        $this->getJson('/api/v1/salary-advance/paid')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_fee_is_income_only_when_collected_and_only_once(): void
    {
        $advance = $this->pendingAdvance();
        $ledger = app(Ledger::class);
        $company = $this->admin->company_id;
        $branch = $this->admin->branch_id;

        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/collect-fee")->assertUnprocessable()
            ->assertJsonPath('errors.fee.0', 'The fee can only be collected on an approved salary advance.');
        $this->getJson('/api/v1/salary-advance/requested')->assertOk()->assertJsonPath('data.0.fee_status', SalaryAdvance::FEE_NOT_APPROVED);

        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/approve")->assertOk();
        $this->assertSame(0, JournalEntry::whereHas('lines.account', fn ($query) => $query->where('key', Account::FeeIncome->value))->count(), 'approval posts no fee line');
        $this->getJson('/api/v1/salary-advance/active')->assertOk()
            ->assertJsonPath('data.0.fee_status', SalaryAdvance::FEE_UNCOLLECTED)
            ->assertJsonPath('data.0.fee_collectable', true);

        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/collect-fee", ['method' => 'MOBILE', 'reference' => 'MP123'])->assertOk()
            ->assertJsonPath('message', 'Salary Advance Fee Collected successfully')
            ->assertJsonPath('data.fee_status', SalaryAdvance::FEE_COLLECTED)
            ->assertJsonPath('data.fee_collectable', false);
        $this->assertEquals(200, $ledger->balance($company, Account::LoanFee, $branch));
        $this->assertEquals(200, $ledger->balance($company, Account::FeeIncome, $branch));
        $advance->refresh();
        $this->assertNotNull($advance->fee_collected_at);
        $this->assertSame($this->admin->id, $advance->fee_collected_by);
        $this->assertSame(['MOBILE', 'MP123'], [$advance->fee_collection_method, $advance->fee_collection_reference]);
        $entry = JournalEntry::with('lines.account')->findOrFail($advance->fee_journal_entry_id);
        $this->assertEquals([['loan_fee', 200.0, 0.0], ['fee_income', 0.0, 200.0]], $entry->lines->map(fn ($line): array => [$line->account->key->value, (float) $line->debit, (float) $line->credit])->all());

        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/collect-fee")->assertUnprocessable()
            ->assertJsonPath('errors.fee.0', 'The fee of this salary advance has already been collected.');
        $this->assertEquals(200, $ledger->balance($company, Account::FeeIncome, $branch), 'collected once');

        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/payments", ['amount' => 24000])->assertOk();
        $this->assertSame('done', $advance->fresh()->status);
        $this->assertEquals(200, $ledger->balance($company, Account::FeeIncome, $branch), 'repayment never touches the fee');
    }

    public function test_an_approved_advance_without_collected_fee_reverses_without_fee_lines(): void
    {
        $advance = $this->pendingAdvance();
        $ledger = app(Ledger::class);
        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/approve")->assertOk();

        $this->asApprover($this->admin, fn () => $this->deleteJson("/api/v1/salary-advance/advances/{$advance->id}", ['reason' => 'Wrong customer'])->assertOk());

        $this->assertSame(1, JournalEntry::whereNotNull('reversal_of_id')->count());
        $this->assertSame(0, JournalEntry::whereHas('lines.account', fn ($query) => $query->whereIn('key', [Account::FeeIncome->value, Account::LoanFee->value]))->count());
        $this->assertEquals(0, $ledger->balance($this->admin->company_id, Account::SalaryAdvanceReceivable, $this->admin->branch_id));
    }

    public function test_a_legacy_advance_whose_fee_was_posted_at_approval_stays_as_booked_and_counts_as_collected(): void
    {
        $advance = $this->pendingAdvance();
        $advance->update(['status' => 'active', 'approved_at' => now()]);
        $ledger = app(Ledger::class);
        $ledger->journal($advance->company_id, 'SALARY ADVANCE LOAN', [
            ['account' => Account::SalaryAdvanceReceivable, 'branch' => $advance->branch_id, 'debit' => 20000],
            ['account' => Account::HqSalaryAdvance, 'credit' => 20000],
            ['account' => Account::LoanFee, 'branch' => $advance->branch_id, 'debit' => 200],
            ['account' => Account::FeeIncome, 'branch' => $advance->branch_id, 'credit' => 200],
        ], $advance, null, $advance->branch_id);

        $this->getJson('/api/v1/salary-advance/active')->assertOk()
            ->assertJsonPath('data.0.fee_status', SalaryAdvance::FEE_COLLECTED_AT_APPROVAL)
            ->assertJsonPath('data.0.fee_collectable', false);
        $this->postJson("/api/v1/salary-advance/advances/{$advance->id}/collect-fee")->assertUnprocessable()
            ->assertJsonPath('errors.fee.0', 'The fee of this salary advance has already been collected.');
        $this->assertEquals(200, $ledger->balance($advance->company_id, Account::FeeIncome, $advance->branch_id));
        $this->assertNull($advance->fresh()->fee_journal_entry_id);

        $this->asApprover($this->admin, fn () => $this->deleteJson("/api/v1/salary-advance/advances/{$advance->id}", ['reason' => 'Legacy error'])->assertOk());
        $this->assertEquals(0, $ledger->balance($advance->company_id, Account::FeeIncome, $advance->branch_id), 'legacy fee is mirrored with the approval journal');
    }

    public function test_role_without_permission_is_forbidden(): void
    {
        $teller = Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', 'teller')->value('id'),
        ]);
        $this->actingAs($teller);

        $this->getJson('/api/v1/salary-advance/requested')->assertForbidden();
        $this->postJson('/api/v1/salary-advance/categories', [])->assertForbidden();
        $this->postJson("/api/v1/salary-advance/advances/{$this->pendingAdvance()->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/salary-advance/advances/{$this->pendingAdvance()->id}/collect-fee")->assertForbidden();
    }

    public function test_hq_finance_lists_categories_but_only_admins_manage_them(): void
    {
        $finance = Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', 'finance')->value('id'),
        ]);
        $this->assertTrue($finance->can('salary_advance.manage'));
        $this->actingAs($finance);

        $this->getJson('/api/v1/salary-advance/categories')->assertOk();
        $this->postJson('/api/v1/salary-advance/categories', ['perferal_name' => 'NEW', 'interest_name' => 10, 'from_amount' => 1000, 'to_amount' => 5000, 'fee_charger' => 0])->assertForbidden();
        $this->putJson("/api/v1/salary-advance/categories/{$this->category->id}", ['perferal_name' => 'X', 'interest_name' => 10, 'from_amount' => 1000, 'to_amount' => 5000, 'fee_charger' => 0])->assertForbidden();
        $this->deleteJson("/api/v1/salary-advance/categories/{$this->category->id}")->assertForbidden();
    }

    public function test_branch_scope_and_company_isolation(): void
    {
        $otherBranch = Branch::factory()->create(['company_id' => $this->admin->company_id]);
        $otherCustomer = Customer::factory()->create(['branch_id' => $otherBranch->id]);
        $this->pendingAdvance();
        $foreignAdvance = $this->pendingAdvance($otherCustomer);

        $finance = Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', 'branch_manager')->value('id'),
        ]);
        $finance->role->permissions()->create(['permission' => 'salary_advance.manage']);
        $this->actingAs($finance->fresh());

        $this->getJson('/api/v1/salary-advance/requested')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/salary-advance/advances/{$foreignAdvance->id}/approve")->assertForbidden();
        $this->postJson('/api/v1/salary-advance/advances', [
            'blanch_id' => $otherBranch->id, 'customer_id' => $otherCustomer->id, 'per_id' => $this->category->id, 'loan_amount' => 20000,
        ])->assertForbidden();

        $this->actingAs($this->admin);
        $otherCompanyCustomer = Customer::factory()->create();
        $otherCompanyCategory = SalaryAdvanceCategory::create(['company_id' => $otherCompanyCustomer->company_id, 'name' => 'OTHER', 'interest_rate' => 10, 'amount_from' => 1000, 'amount_to' => 5000]);
        $this->deleteJson("/api/v1/salary-advance/categories/{$otherCompanyCategory->id}")->assertNotFound();
    }

    private function pendingAdvance(?Customer $customer = null): SalaryAdvance
    {
        $customer ??= $this->customer;

        return SalaryAdvance::create([
            'company_id' => $customer->company_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'salary_advance_category_id' => $this->category->id,
            'amount' => 20000,
            'interest_rate' => 20,
            'total_payable' => 24000,
            'fee' => 200,
        ]);
    }
}
