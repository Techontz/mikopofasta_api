<?php

namespace Tests\Feature\Accounting;

use App\Enums\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Models\ExpenseType;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LoanCategory;
use App\Models\ShareHolder;
use App\Services\Accounting\LedgerIntegrity;
use App\Services\CapitalContributions;
use App\Services\ExpenseApproval;
use App\Services\FloatService;
use App\Services\Ledger;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class LedgerIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->admin->company->update(['reserve_percent' => 20]);
    }

    public function test_a_full_money_flow_through_the_services_passes_every_check(): void
    {
        $this->runMoneyFlow();

        $report = app(LedgerIntegrity::class)->run($this->admin->company_id);
        $checks = collect($report['checks'])->keyBy('key');

        $this->assertSame('pass', $report['status'], json_encode($checks->where('status', '!=', 'pass')->where('status', '!=', 'info')->values()));
        $this->assertSame(0, $report['summary']['fail']);
        $this->assertSame(0, $report['summary']['warn']);
        $this->assertEquals(1000000, $checks['capital']['details']['contributions_register']);
        $this->assertEquals(1000000, $checks['capital']['details']['ledger_capital']);
        $this->assertEquals(0, collect($checks['loan_receivable']['details']['branches'])->sum('difference'));
        $this->assertEquals(40000, collect($checks['loan_receivable']['details']['branches'])->sum('ledger'));
        $this->assertSame(1, $checks['reversals']['details']['reversal_entries']);
        $this->assertSame(0, $checks['transaction_type']['details']['count']);
    }

    public function test_a_tampered_unbalanced_line_is_detected(): void
    {
        $this->runMoneyFlow();
        $line = JournalLine::query()->where('debit', '>', 0)->firstOrFail();
        DB::table('journal_lines')->where('id', $line->id)->update(['debit' => (float) $line->debit + 10]);

        $report = app(LedgerIntegrity::class)->run($this->admin->company_id);
        $checks = collect($report['checks'])->keyBy('key');

        $this->assertSame('fail', $report['status']);
        $this->assertSame('fail', $checks['debits_equal_credits']['status']);
        $this->assertEquals(10, $checks['debits_equal_credits']['details']['difference']);
        $this->assertSame('fail', $checks['entries_balanced']['status']);
        $this->assertSame($line->entry->reference, $checks['entries_balanced']['details']['unbalanced'][0]['reference']);
    }

    public function test_negative_funds_and_sub_ledger_differences_are_reported(): void
    {
        $ledger = app(Ledger::class);
        $ledger->transfer($this->admin->company_id, ['account' => Account::Principal], ['account' => Account::LoanReceivable, 'branch' => $this->admin->branch_id], 5000, 'UNTRACKED LOAN');

        $checks = collect(app(LedgerIntegrity::class)->run($this->admin->company_id)['checks'])->keyBy('key');

        $this->assertSame('fail', $checks['negative_funds']['status']);
        $this->assertEquals(-5000, $checks['negative_funds']['details']['negative'][0]['balance']);
        $this->assertSame('fail', $checks['loan_receivable']['status']);
        $this->assertEquals(5000, $checks['loan_receivable']['details']['branches'][0]['difference']);
    }

    public function test_command_and_endpoint_report_the_same_checks(): void
    {
        $this->runMoneyFlow();

        $this->artisan('ledger:reconcile', ['--company' => $this->admin->company_id])->assertSuccessful();
        $this->artisan('ledger:reconcile', ['--company' => $this->admin->company_id, '--json' => true])
            ->expectsOutputToContain('"key": "debits_equal_credits"')->assertSuccessful();

        $this->getJson('/api/v1/accounting/integrity')->assertOk()
            ->assertJsonPath('data.company_id', $this->admin->company_id)
            ->assertJsonPath('data.status', 'pass')
            ->assertJsonCount(26, 'data.checks');

        $teller = Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', 'teller')->value('id'),
        ]);
        $this->actingAs($teller)->getJson('/api/v1/accounting/integrity')->assertForbidden();

        DB::table('journal_lines')->where('id', JournalLine::query()->value('id'))->update(['credit' => 0, 'debit' => 1]);
        $this->artisan('ledger:reconcile', ['--company' => $this->admin->company_id])->assertFailed();
    }

    /**
     * Capital → float → loan disbursement → repayment → expense → manual entry and its reversal, all through the
     * real services and endpoints.
     */
    private function runMoneyFlow(): void
    {
        $companyId = $this->admin->company_id;
        $branchId = $this->admin->branch_id;

        $holder = ShareHolder::create(['company_id' => $companyId, 'first_name' => 'ASHA', 'last_name' => 'HOLDER', 'mobile' => '0777', 'email' => 'asha@example.com', 'date_of_birth' => '1990-01-01']);
        app(CapitalContributions::class)->contribute($holder, 1000000, 'CASH', null, $this->admin);

        // The company funds HQ, never a branch: the lending cash lives in the company-level PRINCIPAL A/C (no branch).
        $float = app(FloatService::class);
        $float->approve($float->requestCompanyToHq($companyId, Account::Company, null, 400000, $this->admin));

        $loans = app(LoanService::class);
        $customer = Customer::factory()->create(['company_id' => $companyId, 'branch_id' => $branchId]);
        $category = LoanCategory::factory()->create(['company_id' => $companyId, 'insurance' => 0, 'fee_value' => 0]);
        $loan = $loans->apply($customer, ['loan_category_id' => $category->id, 'amount_applied' => 100000, 'sessions' => 1, 'formula' => 'SIMPLE', 'fee_deduct' => false, 'reason' => 'BIASHARA']);
        $loans->approve($loan, 100000);
        $loans->withdraw($loan->fresh(), CarbonImmutable::today(), $this->admin);
        $loans->deposit($loan->fresh(), 40000, CarbonImmutable::today(), 'CASH', $this->admin);
        $loans->deposit($loan->fresh(), 70000, CarbonImmutable::today(), 'CASH', $this->admin);

        $running = $loans->apply($customer, ['loan_category_id' => $category->id, 'amount_applied' => 50000, 'sessions' => 1, 'formula' => 'SIMPLE', 'fee_deduct' => false, 'reason' => 'BIASHARA']);
        $loans->approve($running, 50000);
        $loans->withdraw($running->fresh(), CarbonImmutable::today(), $this->admin);
        $loans->deposit($running->fresh(), 10000, CarbonImmutable::today(), 'CASH', $this->admin);

        $type = ExpenseType::create(['company_id' => $companyId, 'scope' => 'branch', 'name' => 'umeme']);
        app(Ledger::class)->transfer($companyId, ['account' => Account::Company], ['account' => Account::PettyCash, 'branch' => $branchId], 1000, 'PETTY CASH');
        $expense = ExpenseRequest::create([
            'company_id' => $companyId, 'scope' => 'branch', 'branch_id' => $branchId, 'expense_type_id' => $type->id,
            'amount' => 1000, 'description' => 'test', 'status' => 'pending', 'request_date' => today(),
        ]);
        app(ExpenseApproval::class)->accept($expense, $this->admin, 1000, null);

        $manual = app(Ledger::class)->transfer($companyId, ['account' => Account::Company], ['account' => Account::Principal], 5000, 'MANUAL FLOAT');
        // Rule 6: the employee who posted the manual entry does not reverse it.
        $this->approveAsSecondUser($this->admin, "/api/v1/accounting/journal/{$manual->id}/reverse", ['reason' => 'Posted by mistake']);
        $this->assertTrue(JournalEntry::where('reversal_of_id', $manual->id)->exists());
    }
}
