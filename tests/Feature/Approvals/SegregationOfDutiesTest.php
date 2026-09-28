<?php

namespace Tests\Feature\Approvals;

use App\Enums\Account;
use App\Enums\HqFund;
use App\Models\ApprovalPolicy;
use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\Capital;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Models\ExpenseType;
use App\Models\FloatTransfer;
use App\Models\HqTransaction;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Models\PayrollRun;
use App\Models\ShareHolder;
use App\Models\StaffLoan;
use App\Models\StaffLoanCategory;
use App\Models\TellerDeposit;
use App\Services\AccessControl;
use App\Services\Approvals\ReserveProtection;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Ledger;
use App\Services\ShareholderOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UploadsLoanAgreement;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * Rule 6 (segregation of duties: initiator ≠ approver, never implied for Admin, self-approval only through an explicit
 * approvals.self_approve grant; the Super Admin alone may approve their own items but still never reverses what they posted)
 * and rule 3 (no manual outflow from the interest reserve accounts).
 */
class SegregationOfDutiesTest extends TestCase
{
    use RefreshDatabase;
    use UploadsLoanAgreement;
    use UsesSecondApprover;

    private Employee $admin;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->ledger = app(Ledger::class);
    }

    public function test_self_approval_permission_is_never_implied_and_only_counts_when_granted_explicitly(): void
    {
        $access = app(AccessControl::class);
        $this->assertArrayHasKey(SegregationOfDuties::PERMISSION, config('permissions.permissions'));
        $this->assertNotContains(SegregationOfDuties::PERMISSION, $access->permissionsFor($this->admin), 'Super Admin does not receive it automatically');
        $this->assertFalse($access->explicitlyGranted($this->admin, SegregationOfDuties::PERMISSION));
        $this->assertFalse($this->admin->company->roles()->whereHas('permissions', fn ($query) => $query->where('permission', SegregationOfDuties::PERMISSION))->exists(), 'no role holds it by default');

        $finance = $this->employee('finance');
        $this->assertFalse(app(SegregationOfDuties::class)->canSelfApprove($finance));
        $this->grantSelfApproval($finance);
        $this->assertTrue(app(SegregationOfDuties::class)->canSelfApprove($finance));

        $finance->permissionOverrides()->where('permission', SegregationOfDuties::PERMISSION)->update(['granted' => false]);
        $finance->unsetRelation('permissionOverrides');
        $this->assertFalse(app(SegregationOfDuties::class)->canSelfApprove($finance));

        $adminRole = $this->employee('admin');
        $adminRole->role->permissions()->create(['permission' => SegregationOfDuties::PERMISSION]);
        $this->assertTrue(app(SegregationOfDuties::class)->canSelfApprove($adminRole->fresh()));
    }

    public function test_float_initiator_cannot_approve_and_pending_posts_nothing(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::Company, 1000000);
        $entries = JournalEntry::count();
        $initiator = $this->employee('admin');

        $id = $this->actingAs($initiator)->postJson('/api/v1/capital/floats', ['amount' => 400000, 'from_account' => Account::Company->value])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        $this->assertSame($entries, JournalEntry::count(), 'a pending float posts nothing');
        $this->assertSame(1000000.0, $this->ledger->balance($this->admin->company_id, Account::Company));
        $this->assertSame(0.0, $this->ledger->balance($this->admin->company_id, Account::Principal));
        $this->getJson('/api/v1/capital/floats')->assertOk()
            ->assertJsonPath('data.0.can_approve', false)
            ->assertJsonPath('data.0.approve_blocked_reason', SegregationOfDuties::INITIATOR_MESSAGE)
            ->assertJsonPath('total', 0)
            ->assertJsonPath('total_pending', 400000);

        $this->postJson("/api/v1/capital/floats/{$id}/approve")->assertForbidden()->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);
        $this->actingAs($this->employee('teller'))->postJson("/api/v1/capital/floats/{$id}/approve")->assertForbidden()->assertJsonPath('message', 'You do not have permission to perform this action.');

        $approver = $this->employee('admin');
        $this->actingAs($approver)->getJson('/api/v1/capital/floats')->assertJsonPath('data.0.can_approve', true)->assertJsonPath('data.0.approve_blocked_reason', null);
        $this->actingAs($approver)->postJson("/api/v1/capital/floats/{$id}/approve")->assertOk();
        $this->actingAs($approver)->postJson("/api/v1/capital/floats/{$id}/approve")->assertUnprocessable();
        $this->actingAs($this->employee('admin'))->postJson("/api/v1/capital/floats/{$id}/approve")->assertUnprocessable();

        $transfer = FloatTransfer::findOrFail($id);
        $this->assertSame('approved', $transfer->status);
        $this->assertSame($initiator->id, $transfer->requested_by);
        $this->assertSame($approver->id, $transfer->approved_by);
        $this->assertSame($entries + 1, JournalEntry::count(), 'double approval posts once');
        $this->assertSame($approver->id, JournalEntry::findOrFail($transfer->journal_entry_id)->employee_id);
        $this->assertSame(600000.0, $this->ledger->balance($this->admin->company_id, Account::Company));
        $this->assertSame(400000.0, $this->ledger->balance($this->admin->company_id, Account::Principal));
    }

    public function test_super_admin_approves_their_own_float_without_a_grant_but_cannot_reverse_what_they_posted(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::Company, 1000000);
        $this->assertFalse(app(AccessControl::class)->explicitlyGranted($this->admin, SegregationOfDuties::PERMISSION));

        $id = $this->postJson('/api/v1/capital/floats', ['amount' => 400000, 'from_account' => Account::Company->value])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/capital/floats')->assertJsonPath('data.0.can_approve', true)->assertJsonPath('data.0.approve_blocked_reason', null);
        $this->postJson("/api/v1/capital/floats/{$id}/approve")->assertOk();

        $transfer = FloatTransfer::findOrFail($id);
        $this->assertSame('approved', $transfer->status);
        $this->assertSame($this->admin->id, $transfer->requested_by);
        $this->assertSame($this->admin->id, $transfer->approved_by);
        $this->assertSame(400000.0, $this->ledger->balance($this->admin->company_id, Account::Principal));

        $this->getJson('/api/v1/capital/floats')->assertJsonPath('data.0.can_reverse', false)->assertJsonPath('data.0.reverse_blocked_reason', SegregationOfDuties::REVERSER_MESSAGE);
        $this->postJson("/api/v1/capital/floats/{$id}/reverse", ['reason' => 'Mistake'])->assertForbidden()->assertJsonPath('message', SegregationOfDuties::REVERSER_MESSAGE);
        $this->assertSame('approved', $transfer->fresh()->status);
        $this->assertSame(600000.0, $this->ledger->balance($this->admin->company_id, Account::Company));
    }

    public function test_explicit_self_approval_grant_allows_the_initiator_to_approve(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::HqInterest, 100000);
        $initiator = $this->employee('admin');
        $this->actingAs($initiator)->postJson('/api/v1/hq/transactions', ['from_account' => HqFund::OperationIncome->value, 'to_account' => Account::Company->value, 'amount' => 60000])->assertCreated();
        $transaction = HqTransaction::firstOrFail();

        $this->postJson("/api/v1/hq/transactions/{$transaction->id}/approve")->assertForbidden();
        $this->assertSame(100000.0, $this->ledger->balance($this->admin->company_id, Account::HqInterest));

        $this->grantSelfApproval($initiator);
        $this->getJson('/api/v1/hq/transactions')->assertJsonPath('data.0.can_approve', true);
        $this->postJson("/api/v1/hq/transactions/{$transaction->id}/approve")->assertOk();
        $this->assertSame(40000.0, $this->ledger->balance($this->admin->company_id, Account::HqInterest));
    }

    public function test_admin_initiator_is_blocked_until_the_company_grants_self_approval_to_the_role(): void
    {
        $bank = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'NMB']);
        $this->ledger->openingBalance($this->admin->company_id, Account::Bank, 500000, bankAccount: $bank->id);
        $admin = $this->employee('admin');

        $id = $this->actingAs($admin)->postJson('/api/v1/bank/company-transfers', ['direction' => 'bank_to_company', 'bank_account_id' => $bank->id, 'amount' => 100000])->assertCreated()->json('data.id');
        $this->actingAs($admin)->postJson("/api/v1/bank/transfers/{$id}/approve")->assertForbidden()->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);
        $this->actingAs($admin)->getJson('/api/v1/bank/company-transfers')->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.can_approve', false);
        $this->assertSame(500000.0, $this->ledger->balance($this->admin->company_id, Account::Bank, bankAccount: $bank->id));

        $admin->role->permissions()->create(['permission' => SegregationOfDuties::PERMISSION]);
        // C6: the role grant alone is not enough — the company approval policy of the workflow must allow self-approval too.
        $this->actingAs($admin->fresh())->postJson("/api/v1/bank/transfers/{$id}/approve")->assertForbidden();
        $this->allowSelfApprovalPolicy($admin->company_id, [ApprovalPolicy::BANK_TRANSFERS]);
        $this->actingAs($admin->fresh())->postJson("/api/v1/bank/transfers/{$id}/approve")->assertOk();
        $this->assertSame(400000.0, $this->ledger->balance($this->admin->company_id, Account::Bank, bankAccount: $bank->id));
        $this->assertSame(100000.0, $this->ledger->balance($this->admin->company_id, Account::Company));
    }

    public function test_reject_leaves_the_ledger_untouched_and_the_row_cannot_be_approved_later(): void
    {
        $bank = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'CRDB']);
        $this->ledger->openingBalance($this->admin->company_id, Account::Company, 800000);
        $entries = JournalEntry::count();

        $id = $this->postJson('/api/v1/bank/company-transfers', ['direction' => 'company_to_bank', 'bank_account_id' => $bank->id, 'amount' => 300000])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/bank/transfers/{$id}/reject", ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/bank/transfers/{$id}/reject", ['reason' => 'Wrong bank'])->assertOk();

        $transfer = BankTransfer::findOrFail($id);
        $this->assertSame('rejected', $transfer->status);
        $this->assertSame('Wrong bank', $transfer->rejection_reason);
        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame(800000.0, $this->ledger->balance($this->admin->company_id, Account::Company));
        $this->asApprover($this->admin, fn () => $this->postJson("/api/v1/bank/transfers/{$id}/approve")->assertUnprocessable());
        $this->assertSame($entries, JournalEntry::count());
    }

    public function test_pending_rows_of_another_company_are_not_found(): void
    {
        $other = Company::factory()->create();
        $float = FloatTransfer::create(['company_id' => $other->id, 'type' => 'company_to_branch', 'from_account' => 'company_cash', 'to_account' => 'principal', 'amount' => 5, 'status' => 'pending', 'transfer_date' => today()]);
        $bank = BankTransfer::create(['company_id' => $other->id, 'type' => 'company_to_bank', 'amount' => 5, 'status' => 'pending', 'transfer_date' => today()]);
        $holder = ShareHolder::create(['company_id' => $other->id, 'name' => 'X', 'mobile' => '1', 'email' => 'x@example.com', 'date_of_birth' => '1990-01-01']);
        $capital = Capital::create(['company_id' => $other->id, 'share_holder_id' => $holder->id, 'amount' => 5, 'pay_method' => 'CASH', 'receiving_account' => 'company_cash', 'status' => 'pending', 'contributed_at' => now()]);
        $hq = HqTransaction::create(['company_id' => $other->id, 'from_account' => 'hq_interest', 'to_account' => 'hq_disbursement', 'amount' => 5, 'status' => 'pending']);

        $this->postJson("/api/v1/capital/floats/{$float->id}/approve")->assertNotFound();
        $this->postJson("/api/v1/capital/floats/{$float->id}/reject", ['reason' => 'Nope'])->assertNotFound();
        $this->postJson("/api/v1/bank/transfers/{$bank->id}/approve")->assertNotFound();
        $this->postJson("/api/v1/capital/capitals/{$capital->id}/approve")->assertNotFound();
        $this->postJson("/api/v1/hq/transactions/{$hq->id}/approve")->assertNotFound();

        $this->assertSame('pending', $float->fresh()->status);
        $this->assertSame('pending', $capital->fresh()->status);
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_pending_capital_contribution_is_excluded_from_ownership_totals_until_approved(): void
    {
        $holder = ShareHolder::create(['company_id' => $this->admin->company_id, 'name' => 'ALPHA', 'mobile' => '0777', 'email' => 'alpha@example.com', 'date_of_birth' => '1990-01-01']);
        $ownership = app(ShareholderOwnership::class);

        $initiator = $this->employeeWith('admin', 'capital.manage', 'accounting.reverse');

        $id = $this->actingAs($initiator)->postJson('/api/v1/capital/capitals', ['share_id' => $holder->id, 'amount' => 2000000, 'pay_method' => 'CASH'])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.can_approve', false)->json('data.id');

        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0.0, $ownership->totalContributed($this->admin->company_id));
        $this->getJson('/api/v1/capital/capitals')->assertOk()
            ->assertJsonPath('data.share_holder_capital', 0)
            ->assertJsonPath('data.capital_account', 0)
            ->assertJsonPath('data.share_holders.0.capitals.0.status', 'pending')
            ->assertJsonPath('data.share_holders.0.capitals.0.can_reverse', false);
        $this->postJson("/api/v1/capital/capitals/{$id}/reverse", ['reason' => 'Not yet'])->assertUnprocessable();
        $this->postJson("/api/v1/capital/capitals/{$id}/approve")->assertForbidden()->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);

        $approver = $this->secondApprover($this->admin);
        $this->actingAs($approver)->postJson("/api/v1/capital/capitals/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->actingAs($approver)->postJson("/api/v1/capital/capitals/{$id}/approve")->assertUnprocessable();

        $this->assertSame(2000000.0, $ownership->totalContributed($this->admin->company_id));
        $this->assertSame(2000000.0, $this->ledger->balance($this->admin->company_id, Account::Company));
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame($approver->id, Capital::findOrFail($id)->approved_by);

        $rejected = $this->actingAs($initiator)->postJson('/api/v1/capital/capitals', ['share_id' => $holder->id, 'amount' => 700000, 'pay_method' => 'CASH'])->json('data.id');
        $this->postJson("/api/v1/capital/capitals/{$rejected}/reject", ['reason' => 'Duplicate entry'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(2000000.0, $ownership->totalContributed($this->admin->company_id));
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_expense_requester_cannot_accept_it(): void
    {
        $type = ExpenseType::create(['company_id' => $this->admin->company_id, 'scope' => 'branch', 'name' => 'UMEME']);
        $this->ledger->openingBalance($this->admin->company_id, Account::PettyCash, 100000, branch: $this->admin->branch_id);
        $this->actingAs($this->employee('admin'))->postJson('/api/v1/expenses/requests', ['scope' => 'branch', 'blanch_id' => $this->admin->branch_id, 'ex_id' => $type->id, 'req_amount' => 30000, 'req_description' => 'umeme'])->assertCreated();
        $request = ExpenseRequest::firstOrFail();

        $this->getJson('/api/v1/expenses/requests?scope=branch')->assertJsonPath('data.0.can_approve', false)->assertJsonPath('data.0.approve_blocked_reason', SegregationOfDuties::INITIATOR_MESSAGE);
        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept")->assertForbidden();
        $this->assertSame('pending', $request->fresh()->status);

        $this->actingAs($this->employee('finance'))->postJson("/api/v1/expenses/requests/{$request->id}/accept")->assertOk();
        $this->assertSame(70000.0, $this->ledger->balance($this->admin->company_id, Account::PettyCash, $this->admin->branch_id));
    }

    public function test_reserve_accounts_are_not_manual_transfer_or_expense_sources(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::Reserve, 50000, branch: $this->admin->branch_id);
        $this->ledger->openingBalance($this->admin->company_id, Account::HqReserve, 50000);
        $entries = JournalEntry::count();

        // The RESERVE row of an HQ transaction is the one way out, and only towards the Investment RESERVE A/C — anywhere
        // else is refused, and the money moves only when an owner approves, never on Finance's word.
        $this->postJson('/api/v1/hq/transactions', ['from_account' => HqFund::Reserve->value, 'to_account' => Account::Company->value, 'amount' => 1000])
            ->assertUnprocessable()->assertJsonValidationErrors('to_account');
        $reserveOut = $this->postJson('/api/v1/hq/transactions', ['from_account' => HqFund::Reserve->value, 'to_account' => Account::InvestmentReserve->value, 'amount' => 1000])
            ->assertCreated()->json('data.id');
        $this->actingAs($this->employee('finance'))->postJson("/api/v1/hq/transactions/{$reserveOut}/approve")->assertForbidden();
        $this->actingAs($this->admin);

        $type = ExpenseType::create(['company_id' => $this->admin->company_id, 'scope' => 'hq', 'name' => 'KODI']);
        $expense = ExpenseRequest::create(['company_id' => $this->admin->company_id, 'scope' => 'hq', 'expense_type_id' => $type->id, 'employee_id' => $this->employee('finance')->id, 'amount' => 1000, 'status' => 'pending', 'request_date' => today()]);
        $this->postJson("/api/v1/expenses/requests/{$expense->id}/accept", ['from_account' => Account::HqReserve->value])
            ->assertUnprocessable()->assertJsonPath('errors.from_account.0', ReserveProtection::MESSAGE);

        // Legacy pending rows created before the rule are blocked at approval too — the float account → account flow no
        // longer has an endpoint, so its historic rows are the only way a reserve float can still be offered for posting.
        $legacyHq = HqTransaction::create(['company_id' => $this->admin->company_id, 'from_account' => Account::HqReserve->value, 'to_account' => Account::HqInterest->value, 'amount' => 1000, 'status' => 'pending']);
        $legacyFloat = FloatTransfer::create(['company_id' => $this->admin->company_id, 'type' => 'account_to_account', 'from_branch_id' => $this->admin->branch_id, 'to_branch_id' => $this->admin->branch_id, 'from_account' => Account::Reserve->value, 'to_account' => Account::Principal->value, 'amount' => 1000, 'status' => 'pending', 'transfer_date' => today()]);
        $this->postJson("/api/v1/hq/transactions/{$legacyHq->id}/approve")->assertUnprocessable()->assertJsonPath('errors.amount.0', ReserveProtection::MESSAGE);
        $this->postJson("/api/v1/capital/floats/{$legacyFloat->id}/approve")->assertUnprocessable()->assertJsonPath('errors.transfer.0', ReserveProtection::MESSAGE);

        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame(50000.0, $this->ledger->balance($this->admin->company_id, Account::Reserve, $this->admin->branch_id));
        $this->assertSame(50000.0, $this->ledger->balance($this->admin->company_id, Account::HqReserve));

        $this->assertNotContains(Account::Reserve->value, array_column($this->getJson('/api/v1/hq/options/accounts?with_company=1')->json('data'), 'value'));
        $this->assertNotContains(Account::HqReserve->value, array_column($this->getJson('/api/v1/hq/options/accounts?with_company=1')->json('data'), 'value'));
        // The destination list is the shareholders' accounts now, and their RESERVE A/C still takes money.
        $this->assertContains(Account::InvestmentReserve->value, array_column($this->getJson('/api/v1/hq/options/accounts?direction=to')->json('data'), 'value'), 'reserve stays a valid destination');
    }

    public function test_the_employee_who_posted_a_transfer_cannot_reverse_it(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::Company, 1000);
        $id = $this->postJson('/api/v1/capital/floats', ['amount' => 1000, 'from_account' => Account::Company->value])->json('data.id');
        $poster = $this->employeeWith('admin', 'accounting.reverse');
        $this->actingAs($poster)->postJson("/api/v1/capital/floats/{$id}/approve")->assertOk();

        $this->actingAs($poster)->getJson('/api/v1/capital/floats')->assertJsonPath('data.0.can_reverse', false)->assertJsonPath('data.0.reverse_blocked_reason', SegregationOfDuties::REVERSER_MESSAGE);
        $this->actingAs($poster)->postJson("/api/v1/capital/floats/{$id}/reverse", ['reason' => 'Mistake'])->assertForbidden()->assertJsonPath('message', SegregationOfDuties::REVERSER_MESSAGE);
        $this->assertSame('approved', FloatTransfer::findOrFail($id)->status);

        $this->actingAs($this->admin)->postJson("/api/v1/capital/floats/{$id}/reverse", ['reason' => 'Mistake'])->assertOk();
        $this->assertSame(1000.0, $this->ledger->balance($this->admin->company_id, Account::Company));
    }

    public function test_admin_granted_accounting_reverse_by_employee_override_can_reverse_with_all_protections(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::HqInterest, 10000);
        $finance = $this->employee('finance');
        $transaction = HqTransaction::create(['company_id' => $this->admin->company_id, 'employee_id' => $finance->id, 'from_account' => Account::HqInterest->value, 'to_account' => Account::HqDisbursement->value, 'amount' => 4000, 'status' => 'pending']);
        $this->postJson("/api/v1/hq/transactions/{$transaction->id}/approve")->assertOk();

        $admin = $this->employee('admin');
        $access = app(AccessControl::class);
        $access->syncEmployeePermissions($admin, array_values(array_diff($access->permissionsFor($admin), ['accounting.reverse'])));
        $this->actingAs($admin->fresh())->postJson("/api/v1/hq/transactions/{$transaction->id}/reverse", ['reason' => 'Mistake'])->assertForbidden();

        $access->syncEmployeePermissions($admin->fresh(), [...$access->permissionsFor($admin->fresh()), 'accounting.reverse']);
        $this->assertTrue($access->can($admin->fresh(), 'accounting.reverse'));

        $this->ledger->journal($this->admin->company_id, 'SPEND', [['account' => Account::OperatingExpense, 'debit' => 3000], ['account' => Account::HqDisbursement, 'credit' => 3000]]);
        $this->actingAs($admin->fresh())->postJson("/api/v1/hq/transactions/{$transaction->id}/reverse", ['reason' => 'Mistake'])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->ledger->journal($this->admin->company_id, 'UNDO SPEND', [['account' => Account::HqDisbursement, 'debit' => 3000], ['account' => Account::OperatingExpense, 'credit' => 3000]]);
        $this->actingAs($admin->fresh())->postJson("/api/v1/hq/transactions/{$transaction->id}/reverse", ['reason' => 'Mistake'])->assertOk();
        $this->assertSame(10000.0, $this->ledger->balance($this->admin->company_id, Account::HqInterest));
        $this->assertSame('reversed', $transaction->fresh()->status);
    }

    public function test_payroll_preparer_can_neither_approve_nor_pay(): void
    {
        $hr = $this->employee('hr');
        $finance = $this->employee('finance');
        // Finance is the final payroll approver (ruling 2026-09-17); HR, who prepares payroll, may not approve at all.
        $run = PayrollRun::create(['company_id' => $this->admin->company_id, 'period' => today()->startOfMonth(), 'status' => PayrollRun::STATUS_DRAFT, 'prepared_by' => $hr->id]);
        $this->actingAs($hr)->getJson('/api/v1/hrm/payroll?period='.today()->format('Y-m'))->assertJsonPath('data.run.can_approve', false);
        $this->actingAs($hr)->postJson("/api/v1/hrm/payroll/{$run->id}/approve")->assertForbidden();

        // A Finance user who prepared the run still cannot approve it.
        $run->update(['prepared_by' => $finance->id]);
        $this->actingAs($finance)->getJson('/api/v1/hrm/payroll?period='.today()->format('Y-m'))->assertJsonPath('data.run.can_approve', false)->assertJsonPath('data.run.approve_blocked_reason', SegregationOfDuties::INITIATOR_MESSAGE);
        $this->actingAs($finance)->postJson("/api/v1/hrm/payroll/{$run->id}/approve")->assertForbidden();
        $this->assertSame(PayrollRun::STATUS_DRAFT, $run->fresh()->status);

        $run->update(['status' => PayrollRun::STATUS_APPROVED, 'prepared_by' => $finance->id, 'approved_by' => $hr->id]);
        $this->actingAs($finance)->postJson("/api/v1/hrm/payroll/{$run->id}/pay", ['ac_id' => 'interest'])->assertForbidden()->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);
        $this->assertSame(PayrollRun::STATUS_APPROVED, $run->fresh()->status);
    }

    public function test_staff_loan_requester_and_beneficiary_cannot_approve_and_approver_cannot_disburse(): void
    {
        $staff = $this->employee('teller');
        $requester = $this->employee('hr');
        $category = StaffLoanCategory::create(['company_id' => $this->admin->company_id, 'name' => 'SL', 'amount_from' => 1000, 'amount_to' => 10000, 'interest_rate' => 10, 'duration' => 'monthly', 'repayment_from' => 1, 'repayment_to' => 3, 'fee' => 0]);
        $this->actingAs($requester)->postJson('/api/v1/hrm/staff-loans', ['blanch_id' => $staff->branch_id, 'empl_id' => $staff->id, 'category_id' => $category->id, 'loan_amount' => 5000, 'day' => 'monthly', 'session' => 2, 'reason' => 'Fees'])->assertCreated();
        $loan = StaffLoan::firstOrFail();
        $this->assertSame($requester->id, $loan->requested_by);

        $this->postJson("/api/v1/hrm/staff-loans/{$loan->id}/approve")->assertForbidden();
        $this->actingAs($staff)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/approve")->assertForbidden();
        $approver = $this->employeeWith('hr', 'payroll.pay');
        $this->actingAs($approver)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/approve")->assertOk();
        $this->actingAs($approver)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/finance-approve")->assertForbidden()->assertJsonPath('message', SegregationOfDuties::STAGE_MESSAGE);
        $this->assertSame('hr_approved', $loan->fresh()->status);
    }

    public function test_teller_cannot_verify_or_confirm_own_bank_deposit(): void
    {
        $bank = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'NMB']);
        $finance = $this->employee('finance');
        $deposit = TellerDeposit::create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'employee_id' => $finance->id, 'bank_account_id' => $bank->id, 'slip_number' => 'S-1', 'amount' => 1000, 'deposit_date' => today(), 'status' => TellerDeposit::STATUS_PENDING]);

        $this->actingAs($finance)->postJson("/api/v1/payments/reconciliation/{$deposit->id}/verify", ['statement_amount' => 1000, 'statement_reference' => 'ST-1'])->assertForbidden();
        $this->postJson("/api/v1/payments/reconciliation/{$deposit->id}/confirm")->assertForbidden();
        $this->assertSame(TellerDeposit::STATUS_PENDING, $deposit->fresh()->status);
    }

    public function test_loan_applicant_cannot_approve_and_a_manager_approver_cannot_approve_the_credit_stage(): void
    {
        config(['integrations.vodacom.driver' => 'test', 'integrations.bank_mandate.driver' => 'test', 'integrations.vodacom.test_outcome' => 'success']);
        $category = LoanCategory::factory()->create(['company_id' => $this->admin->company_id, 'insurance' => 0]);
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'phone' => '2557540'.random_int(10000, 99999), 'customer_category_id' => $category->customer_category_id]);
        $category->branches()->attach($this->admin->branch_id);

        $applicant = $this->employeeWith('branch_manager', 'loans.apply', 'loans.credit_review');
        $this->actingAs($applicant)->postJson(route('api.v1.loans.store'), ['customer_id' => $customer->id, 'category_id' => $category->id, 'how_loan' => 100000, 'session' => 1, 'rate' => 'SIMPLE', 'fee_status' => 'NO', 'reason' => 'BIASHARA'])->assertCreated();
        $loan = Loan::latest('id')->firstOrFail();

        $this->postJson(route('api.v1.loans.approve-manager', $loan), ['loan_aprove' => 100000])->assertForbidden()->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);

        $manager = $this->employeeWith('branch_manager', 'loans.credit_review');
        $this->actingAs($manager)->postJson(route('api.v1.loans.approve-manager', $loan), ['loan_aprove' => 100000])->assertOk();
        $this->actingAs($manager)->postJson(route('api.v1.loans.kyc-verify', $loan))->assertOk();
        $this->uploadAgreement($loan)->assertOk();
        $this->actingAs($manager)->postJson(route('api.v1.loans.approve-credit', $loan))->assertForbidden()->assertJsonPath('message', SegregationOfDuties::STAGE_MESSAGE);
        $this->actingAs($applicant)->postJson(route('api.v1.loans.approve-credit', $loan))->assertForbidden()->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);

        $this->actingAs($this->employee('credit_officer'))->postJson(route('api.v1.loans.approve-credit', $loan))->assertOk();
    }

    private function employee(string $role): Employee
    {
        return $this->secondApprover($this->admin, $role);
    }

    /**
     * An employee of the given role with extra permissions granted by employee override (a non-exempt initiator).
     */
    private function employeeWith(string $role, string ...$permissions): Employee
    {
        $employee = $this->employee($role);

        foreach ($permissions as $permission) {
            $employee->permissionOverrides()->create(['permission' => $permission, 'granted' => true]);
        }

        return $employee;
    }
}
