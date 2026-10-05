<?php

namespace Tests\Feature\Api\Imports;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\LegacyImport;
use App\Models\LegacyImportRow;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\SalaryAdvance;
use App\Services\Accounting\LedgerIntegrity;
use App\Services\Ledger;
use App\Services\LegacyImports\LegacyFileFormat;
use App\Services\LegacyImports\SpreadsheetReader;
use App\Services\LoanService;
use App\Services\LoanWorkflow;
use App\Services\SalaryAdvanceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Accounting\AccountingTestHelpers;
use Tests\TestCase;

/**
 * Legacy loan data import & export: the old system's Loan File, Penalty List and Active Salary Advance list brought in as
 * opening balances — three separate debts, approved by someone other than the uploader, never counted twice.
 */
class LegacyImportTest extends TestCase
{
    use AccountingTestHelpers, RefreshDatabase;

    private const LOAN_HEADER = 'No.,Branch Name,Customer Name,Phone Number,Loan Amount,Duration Type / Number,Collection,Paid Amount,Remain Amount,Withdrawal Date,Loan Status,January,February,March,April,May,June,July,August,September';

    private const PENALTY_HEADER = 'No.,Customer Name,Branch Name,Loan Amount,Penalty Amount,Date,Accounting,Action';

    private const ADVANCE_HEADER = 'No.,Customer Name,Branch Name,Loan Amount,Interest,Principal + Interest,Paid Amount,Remain Amount,Status,Carger,Date Alert,Action';

    private Employee $superAdmin;

    private Employee $finance;

    private Employee $admin;

    private Branch $branch;

    private Customer $john;

    private Customer $jane;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->signInAdmin();
        $this->branch = Branch::findOrFail($this->superAdmin->branch_id);
        $this->branch->update(['name' => 'Kariakoo']);
        $this->finance = $this->employeeWithRole($this->superAdmin, 'finance');
        $this->admin = $this->employeeWithRole($this->superAdmin, 'admin');
        CustomerCategory::factory()->create(['company_id' => $this->superAdmin->company_id]);

        $this->john = Customer::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'JOHN', 'middle_name' => null, 'last_name' => 'SMITH', 'phone' => '0712345678']);
        $this->jane = Customer::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'JANE', 'middle_name' => null, 'last_name' => 'SMITH', 'phone' => '255700000001']);
    }

    public function test_a_loan_file_goes_live_only_after_another_user_approves_it(): void
    {
        $import = $this->upload($this->finance, 'loan', $this->loanFile(), year: 2026);

        $import->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.valid_rows', 2)
            ->assertJsonPath('data.totals.loan_outstanding', 1500000)
            ->assertJsonPath('data.totals.active_outstanding', 500000)
            ->assertJsonPath('data.totals.default_outstanding', 1000000);
        $id = $import->json('data.id');
        $this->assertSame(0, Loan::count(), 'Nothing reaches a balance before approval.');

        $this->actingAs($this->finance)->postJson("/api/v1/legacy-imports/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $this->actingAs($this->finance)->postJson("/api/v1/legacy-imports/{$id}/approve")->assertForbidden();
        $this->actingAs($this->superAdmin)->postJson("/api/v1/legacy-imports/{$id}/approve")->assertOk();

        $this->assertDatabaseHas('legacy_imports', ['id' => $id, 'status' => 'approved', 'approved_by' => $this->superAdmin->id, 'uploaded_by' => $this->finance->id]);

        $loans = app(LoanService::class);
        $johnLoan = Loan::where('customer_id', $this->john->id)->firstOrFail();
        $janeLoan = Loan::where('customer_id', $this->jane->id)->firstOrFail();
        $this->assertTrue($johnLoan->is_legacy_opening);
        $this->assertSame(LoanStatus::Active, $johnLoan->status);
        $this->assertSame(LoanStatus::Default, $janeLoan->status);
        $this->assertSame(1200000.0, (float) $johnLoan->amount_approved, 'Loan Amount (principal + old interest) is kept as printed.');
        $this->assertSame(['principal' => 500000.0, 'penalty' => 0.0, 'interest' => 0.0, 'insurance' => 0.0, 'total' => 500000.0], $loans->outstanding($johnLoan));
        $this->assertSame(1000000.0, $loans->outstanding($janeLoan)['principal']);
        $this->assertSame([1 => 50000, 2 => 100000, 9 => 95000], array_map('intval', $johnLoan->legacyImportRow->monthly), 'The monthly history is kept, and never reduced the balance.');

        $portfolio = $this->getJson('/api/v1/reports/portfolio')->assertOk()->json('data.summary');
        $this->assertEquals(3200000, $portfolio['issued_amount']);
        $this->assertEquals(1500000, $portfolio['outstanding_principal']);
        $this->assertEquals(500000, $portfolio['active_outstanding_principal']);
        $this->assertEquals(1000000, $portfolio['default_outstanding_principal']);

        $ledger = app(Ledger::class);
        $this->assertSame(1500000.0, $ledger->balance($this->superAdmin->company_id, Account::LoanReceivable, $this->branch->id));
        $this->assertSame(1500000.0, $ledger->balance($this->superAdmin->company_id, Account::LegacyOpeningBalance, $this->branch->id));
        $this->assertSame(0.0, $ledger->balance($this->superAdmin->company_id, Account::Capital), 'Old-system receivables never show as capital.');
        $this->assertIntegrityPasses();

        // A repayment here reduces the printed Remain Amount once — the old payments are not deducted again.
        $loans->deposit($johnLoan, 200000, CarbonImmutable::today());
        $this->assertSame(300000.0, $loans->outstanding($johnLoan->fresh())['principal']);
        $this->assertIntegrityPasses();
    }

    public function test_only_a_penalty_without_a_loan_holds_back_a_new_loan(): void
    {
        $this->importAndApprove('salary_advance', self::ADVANCE_HEADER."\n1,John Smith,Kariakoo,300000,30000,330000,230000,100000,Active,5000,2026-08-01,\n");

        $status = app(LoanWorkflow::class)->borrowingStatus($this->john->fresh());
        $this->assertStringNotContainsString('salary advance', implode(' ', $status['eligibility_reasons']), 'A salary advance is repaid on its own and never blocks a loan.');

        $this->importAndApprove('penalty', self::PENALTY_HEADER."\n1,JOHN SMITH,Kariakoo,1200000,50000,2026-08-20,Recorded,\n");
        $this->assertNull(Penalty::where('customer_id', $this->john->id)->firstOrFail()->loan_id);

        $status = app(LoanWorkflow::class)->borrowingStatus($this->john->fresh());
        $this->assertFalse($status['eligible']);
        $this->assertContains('Penalty outstanding without a loan: '.money(50000).' — clear it before a new loan', $status['eligibility_reasons']);
    }

    public function test_principal_penalty_and_salary_advance_stay_three_separate_debts(): void
    {
        $this->importAndApprove('loan', $this->loanFile(), 2026);
        $this->importAndApprove('penalty', self::PENALTY_HEADER."\n1,JOHN SMITH,Kariakoo,1200000,50000,2026-08-20,Recorded,\n");
        $this->importAndApprove('salary_advance', self::ADVANCE_HEADER."\n1,John Smith,Kariakoo,300000,30000,330000,230000,100000,Active,5000,2026-08-01,\n");

        $loan = Loan::where('customer_id', $this->john->id)->firstOrFail();
        $penalty = Penalty::where('customer_id', $this->john->id)->firstOrFail();
        $advance = SalaryAdvance::where('customer_id', $this->john->id)->firstOrFail();
        $this->assertSame($loan->id, $penalty->loan_id, 'The penalty is attached to the only old-system loan it can belong to...');
        $this->assertSame(1200000.0, (float) $loan->amount_approved, '...without touching the loan principal.');
        $this->assertSame(100000.0, $advance->remaining_amount);
        $this->assertSame(SalaryAdvance::FEE_OLD_SYSTEM, $advance->feeStatus());

        $this->getJson("/api/v1/customers/{$this->john->id}/debt")->assertOk()
            ->assertJsonPath('data.principal', 500000)
            ->assertJsonPath('data.penalty', 50000)
            ->assertJsonPath('data.salary_advance', 100000)
            ->assertJsonPath('data.total', 650000)
            ->assertJsonPath('data.old_system.total', 650000);

        $this->getJson("/api/v1/teller/customers/{$this->john->id}")->assertOk()
            ->assertJsonPath('data.debt.total', 650000)
            ->assertJsonPath('data.salary_advance', 100000)
            ->assertJsonPath('data.available_to_deposit', 550000);

        $status = app(LoanWorkflow::class)->borrowingStatus($this->john->fresh());
        $this->assertFalse($status['allowed']);
        $this->assertStringContainsString('old-system loan', implode(' ', $status['reasons']));
        $this->assertStringNotContainsString('salary advance', implode(' ', $status['reasons']), 'A salary advance is repaid on its own and never blocks a loan.');
        $this->assertStringNotContainsString('Penalty outstanding', implode(' ', $status['reasons']), 'The penalty is on the old loan, which a top-up settles.');

        // A normal loan payment covers principal and penalty only; the salary advance stays untouched.
        app(LoanService::class)->deposit($loan, 550000, CarbonImmutable::today());
        $this->assertSame(LoanStatus::Closed, $loan->fresh()->status);
        $this->assertSame(50000.0, (float) $penalty->fresh()->paid_amount);
        $this->assertSame(100000.0, $advance->fresh()->remaining_amount);

        // The salary advance has its own payment: principal still owed (70,000) first, then its profit (30,000).
        app(SalaryAdvanceService::class)->pay($advance, 100000);
        $this->assertSame('done', $advance->fresh()->status);
        $this->assertSame(0.0, app(Ledger::class)->balance($this->superAdmin->company_id, Account::SalaryAdvanceReceivable, $this->branch->id));
        $this->assertSame(30000.0, app(Ledger::class)->balance($this->superAdmin->company_id, Account::SalaryAdvanceIncome, $this->branch->id));
        $this->assertIntegrityPasses();
    }

    public function test_the_super_admin_sets_the_share_of_an_old_system_loan_paid_before_a_top_up(): void
    {
        $this->importAndApprove('loan', $this->loanFile(), 2026);
        $loan = Loan::where('customer_id', $this->john->id)->firstOrFail();
        $workflow = app(LoanWorkflow::class);

        // Default 100 %: the old-system loan (58.33 % paid) must be cleared first.
        $topup = $workflow->topupEligibility($loan);
        $this->assertFalse($topup['eligible']);
        $this->assertSame(100.0, $topup['required_percent']);
        $this->assertStringContainsString('must be cleared first', $topup['reasons'][0]);

        $this->actingAs($this->finance)->putJson('/api/v1/settings/legacy-topup', ['legacy_topup_percent' => 50])->assertForbidden();
        $this->actingAs($this->superAdmin)->putJson('/api/v1/settings/legacy-topup', ['legacy_topup_percent' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('legacy_topup_percent');
        $this->putJson('/api/v1/settings/legacy-topup', ['legacy_topup_percent' => 50])->assertOk();
        $this->getJson('/api/v1/settings/legacy-topup')->assertOk()
            ->assertJsonPath('data.legacy_topup_percent', 50)
            ->assertJsonPath('data.can_update', true);

        $topup = $workflow->topupEligibility($loan->fresh());
        $this->assertTrue($topup['eligible']);
        $this->assertSame(50.0, $topup['required_percent']);

        $this->putJson('/api/v1/settings/legacy-topup', ['legacy_topup_percent' => 90])->assertOk();
        $topup = $workflow->topupEligibility($loan->fresh());
        $this->assertFalse($topup['eligible']);
        $this->assertSame(['paid 58.33% of required 90%'], $topup['reasons']);
    }

    public function test_a_file_with_the_wrong_columns_is_refused(): void
    {
        $this->upload($this->finance, 'salary_advance', self::PENALTY_HEADER."\n1,JOHN SMITH,Kariakoo,1200000,50000,2026-08-20,Recorded,\n")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file'])
            ->assertJsonFragment(['Invalid file format for Active Salary Advance Import. The file has 7 columns; 12 are expected: '.implode(', ', LegacyFileFormat::SALARY_ADVANCE).'.']);
    }

    public function test_each_bad_row_is_reported_with_its_row_and_reason(): void
    {
        $file = self::LOAN_HEADER."\n"
            .",Kariakoo,,0712345678,1200000,Monthly / 12,100000,700000,500000,2026-01-10,Active,,,,,,,,,\n"
            ."2,Mwanza,JOHN SMITH,0712345678,1200000,Monthly / 12,100000,700000,500000,2026-01-10,Active,,,,,,,,,\n"
            ."3,Kariakoo,JOHN SMITH,0712345678,1200000,Monthly / 12,100000,700000,500000,2026-01-10,Closed,,,,,,,,,\n"
            ."4,Kariakoo,JANE SMITH,255700000001,1000000,Weekly 10,100000,0,abc,2026-01-10,Default,,,,,,,,,\n"
            ."5,Kariakoo,JOHN SMITH,0712345678,900000,Monthly / 9,100000,100000,850000,2026-02-01,Active,,,,,,,,,\n";

        $id = $this->upload($this->finance, 'loan', $file, year: 2026)->assertCreated()
            ->assertJsonPath('data.error_rows', 4)
            ->assertJsonPath('data.warning_rows', 1)
            ->json('data.id');

        $rows = LegacyImportRow::where('legacy_import_id', $id)->orderBy('row_number')->get()->keyBy('row_number');
        $this->assertContains('Customer Name is missing.', $rows[2]->messages);
        $this->assertContains('Branch not found: "Mwanza".', $rows[3]->messages);
        $this->assertContains('Invalid Loan Status "Closed": it must be Active or Default.', $rows[4]->messages);
        $this->assertContains('Invalid Remain Amount: "abc" is not an amount.', $rows[5]->messages);
        $this->assertSame(LegacyImportRow::STATUS_WARNING, $rows[6]->status, 'Loan − Paid ≠ Remain is a warning: the printed Remain Amount is kept.');

        $exceptions = $this->sheet($this->get("/api/v1/legacy-imports/{$id}/exceptions")->assertOk());
        $this->assertSame([...LegacyFileFormat::LOAN, ...LegacyFileFormat::EXCEPTION_COLUMNS], $exceptions[0]);
        $this->assertStringContainsString('Branch not found', implode(' ', array_merge(...$exceptions)));
    }

    public function test_the_same_records_are_never_imported_twice(): void
    {
        $file = self::ADVANCE_HEADER."\n1,JOHN SMITH,Kariakoo,300000,30000,330000,230000,100000,Active,0,2026-08-01,\n";
        $this->importAndApprove('salary_advance', $file);

        $again = $this->upload($this->finance, 'salary_advance', $file)->assertCreated()->assertJsonPath('data.duplicate_rows', 1);
        $this->assertStringContainsString('Already imported', LegacyImportRow::where('legacy_import_id', $again->json('data.id'))->value('messages')[0]);
        $this->actingAs($this->finance)->postJson('/api/v1/legacy-imports/'.$again->json('data.id').'/submit')->assertUnprocessable();

        $this->assertSame(1, SalaryAdvance::count());
        $this->assertSame(100000.0, SalaryAdvance::firstOrFail()->remaining_amount);
    }

    public function test_an_unmatched_customer_waits_for_an_admin_to_map_it(): void
    {
        $id = $this->upload($this->finance, 'penalty', self::PENALTY_HEADER."\n1,PETER NEW,Kariakoo,500000,25000,2026-08-20,Recorded,\n")
            ->assertCreated()
            ->assertJsonPath('data.unmatched_rows', 1)
            ->json('data.id');
        $row = LegacyImportRow::where('legacy_import_id', $id)->firstOrFail();
        $this->assertStringContainsString('No customer "PETER NEW" was found in this branch', $row->messages[0]);

        $this->actingAs($this->finance)->postJson("/api/v1/legacy-imports/{$id}/rows/{$row->id}/map", ['create' => true])->assertForbidden();
        $this->actingAs($this->admin)->postJson("/api/v1/legacy-imports/{$id}/rows/{$row->id}/map", ['create' => true])->assertOk()
            ->assertJsonPath('data.unmatched_rows', 0)
            ->assertJsonPath('data.valid_rows', 1);

        $created = Customer::where('first_name', 'PETER')->where('last_name', 'NEW')->firstOrFail();
        $this->assertSame('legacy_import', $created->registration_source);
        $this->assertSame($this->branch->id, $created->branch_id);
        $this->assertNull($created->gender, 'Nothing the file does not print is invented.');
    }

    public function test_a_rejected_import_changes_nothing_and_an_approved_one_can_be_rolled_back_until_it_is_used(): void
    {
        $id = $this->upload($this->finance, 'loan', $this->loanFile(), year: 2026)->json('data.id');
        $this->actingAs($this->finance)->postJson("/api/v1/legacy-imports/{$id}/submit")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/legacy-imports/{$id}/reject", ['reason' => 'Wrong branch totals'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Wrong branch totals');
        $this->assertSame(0, Loan::count());

        $id = $this->importAndApprove('loan', $this->loanFile(), 2026);
        $this->assertSame(2, Loan::count());

        $this->actingAs($this->admin)->postJson("/api/v1/legacy-imports/{$id}/rollback", ['reason' => 'Test import'])->assertOk()->assertJsonPath('data.status', 'rolled_back');
        $this->assertSame(0, Loan::count());
        $this->assertSame(0.0, app(Ledger::class)->balance($this->superAdmin->company_id, Account::LoanReceivable, $this->branch->id));
        $this->assertIntegrityPasses();

        // Rolled back, the same file can be imported again; once repaid, it can no longer be rolled back.
        $id = $this->importAndApprove('loan', $this->loanFile(), 2026);
        app(LoanService::class)->deposit(Loan::where('customer_id', $this->john->id)->firstOrFail(), 100000, CarbonImmutable::today());
        $this->actingAs($this->admin)->postJson("/api/v1/legacy-imports/{$id}/rollback", ['reason' => 'Too late'])->assertUnprocessable();
        $this->assertSame(2, Loan::count());
    }

    public function test_export_writes_the_columns_the_import_reads_and_a_re_import_is_recognised(): void
    {
        $this->importAndApprove('loan', $this->loanFile(), 2026);
        $this->importAndApprove('salary_advance', self::ADVANCE_HEADER."\n1,JOHN SMITH,Kariakoo,300000,30000,330000,230000,100000,Active,5000,2026-08-01,\n");

        $loanExport = $this->get('/api/v1/legacy-imports/export?module=loan&loan_status=Active&year=2026')->assertOk();
        $this->assertStringEndsWith('.xlsx', (string) $loanExport->headers->get('content-disposition'));
        $lines = $this->sheet($loanExport);
        $this->assertSame(explode(',', self::LOAN_HEADER), $lines[0]);
        $this->assertCount(2, $lines, 'Only the Active loans.');
        $this->assertSame(['1', 'Kariakoo', 'JOHN SMITH', '0712345678', '1200000', 'Monthly / 12', '100000', '700000', '500000', '2026-01-10', 'Active', '50000', '100000', '0', '0', '0', '0', '0', '0', '95000'], $lines[1]);

        $this->getJson('/api/v1/legacy-imports/export?module=loan')->assertUnprocessable()->assertJsonValidationErrors(['loan_status', 'year']);
        $this->getJson('/api/v1/legacy-imports/export?module=penalty')->assertOk();

        $advanceExport = $this->get('/api/v1/legacy-imports/export?module=salary_advance')->assertOk();
        $advanceLines = $this->sheet($advanceExport);
        $this->assertSame(LegacyFileFormat::SALARY_ADVANCE, $advanceLines[0], 'No Action column: actions belong on the screen only.');
        $this->assertSame(['1', 'JOHN SMITH', 'Kariakoo', '300000', '30000', '330000', '230000', '100000', 'Active', '5000', '2026-08-01', 'OLD'], $advanceLines[1], 'Date and Alert are separate columns.');
        $this->assertSame(LegacyFileFormat::PENALTY, $this->sheet($this->get('/api/v1/legacy-imports/template?module=penalty')->assertOk())[0]);

        $this->upload($this->finance, 'loan', $loanExport->streamedContent(), year: 2026, name: 'loan.xlsx')->assertCreated()->assertJsonPath('data.duplicate_rows', 1);
        $this->upload($this->finance, 'salary_advance', $advanceExport->streamedContent(), name: 'advance.xlsx')->assertCreated()->assertJsonPath('data.duplicate_rows', 1);
    }

    public function test_export_needs_no_branch_and_covers_every_branch_of_the_company_in_one_file(): void
    {
        $missenyi = Branch::factory()->create(['company_id' => $this->branch->company_id, 'name' => 'Missenyi']);
        $elsewhere = Branch::factory()->create(['name' => 'Other Company Branch']);
        foreach ([[$this->branch, 'JOHN SMITH'], [$missenyi, 'ANNA MOSHI'], [$elsewhere, 'NOT OURS']] as [$branch, $name]) {
            [$first, $last] = explode(' ', $name);
            $customer = Customer::factory()->create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'first_name' => $first, 'middle_name' => null, 'last_name' => $last]);
            SalaryAdvance::create([
                'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
                'amount' => 20000, 'interest_rate' => 20, 'total_payable' => 24000, 'fee' => 200, 'status' => 'active', 'approved_at' => '2026-09-01 10:00:00',
            ]);
        }

        $export = $this->actingAs($this->finance)->get('/api/v1/legacy-imports/export?module=salary_advance')->assertOk();
        $lines = $this->sheet($export);

        $this->assertSame([['1', 'JOHN SMITH', 'Kariakoo'], ['2', 'ANNA MOSHI', 'Missenyi']], array_map(fn (array $line): array => array_slice($line, 0, 3), array_slice($lines, 1)));
        $this->assertStringNotContainsString('Kariakoo', (string) $export->headers->get('content-disposition'), 'The file is not named after one branch.');
    }

    public function test_a_remain_amount_that_carries_an_old_fee_is_kept_whole_as_principal(): void
    {
        $this->importAndApprove('loan', self::LOAN_HEADER."\n1,Kariakoo,JOHN SMITH,0712345678,300000,Monthly / 3,105000,0,315000,2024-05-02,Default,,,,,,,,,\n", 2026);

        $loan = Loan::where('customer_id', $this->john->id)->firstOrFail();
        $this->assertSame(300000.0, (float) $loan->amount_approved, 'The printed Loan Amount is kept...');
        $this->assertSame(315000.0, app(LoanService::class)->outstanding($loan)['principal'], '...and the whole printed Remain Amount is owed.');
        $this->assertIntegrityPasses();

        $line = $this->sheet($this->get('/api/v1/legacy-imports/export?module=loan&loan_status=Default&year=2026')->assertOk())[1];
        $this->assertSame(['300000', '0', '315000'], [$line[4], $line[7], $line[8]], 'Loan Amount, Paid and Remain export as printed.');
    }

    public function test_nightly_processing_keeps_the_imported_status(): void
    {
        $this->importAndApprove('loan', $this->loanFile(), 2026);

        app(LoanService::class)->applyPenaltiesAndDefaults(CarbonImmutable::today()->addYear());

        $this->assertSame(LoanStatus::Active, Loan::where('customer_id', $this->john->id)->value('status'));
        $this->assertSame(LoanStatus::Default, Loan::where('customer_id', $this->jane->id)->value('status'));
        $this->assertSame(0, Penalty::count());
    }

    public function test_the_pending_import_is_listed_for_approval_but_not_for_its_uploader(): void
    {
        $id = $this->upload($this->admin, 'loan', $this->loanFile(), year: 2026)->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/v1/legacy-imports/{$id}/submit")->assertOk();

        $own = collect($this->actingAs($this->admin)->getJson('/api/v1/approvals/pending')->assertOk()->json('data.groups'))->firstWhere('label', 'Old System Imports');
        $this->assertFalse($own['rows'][0]['can_approve']);
        $this->actingAs($this->admin)->postJson("/api/v1/legacy-imports/{$id}/approve")->assertForbidden();

        $other = collect($this->actingAs($this->superAdmin)->getJson('/api/v1/approvals/pending')->assertOk()->json('data.groups'))->firstWhere('label', 'Old System Imports');
        $this->assertTrue($other['rows'][0]['can_approve']);
        $this->assertEquals(1500000, $other['rows'][0]['amount']);
    }

    public function test_the_super_admin_may_approve_their_own_import(): void
    {
        $id = $this->upload($this->superAdmin, 'loan', $this->loanFile(), year: 2026)->json('data.id');
        $this->actingAs($this->superAdmin)->postJson("/api/v1/legacy-imports/{$id}/submit")->assertOk();

        $this->getJson("/api/v1/legacy-imports/{$id}")->assertOk()->assertJsonPath('data.can_approve', true)->assertJsonPath('data.approve_blocked_reason', null);
        $own = collect($this->getJson('/api/v1/approvals/pending')->assertOk()->json('data.groups'))->firstWhere('label', 'Old System Imports');
        $this->assertTrue($own['rows'][0]['can_approve']);
        $this->postJson("/api/v1/legacy-imports/{$id}/approve")->assertOk();
        $this->assertDatabaseHas('legacy_imports', ['id' => $id, 'status' => 'approved', 'approved_by' => $this->superAdmin->id, 'uploaded_by' => $this->superAdmin->id]);
    }

    private function loanFile(): string
    {
        return self::LOAN_HEADER."\n"
            ."1,Kariakoo,JOHN SMITH,0712345678,\"1,200,000\",Monthly / 12,100000,700000,500000,2026-01-10,Active,50000,100000,,,,,,,95000\n"
            ."2,KARIAKOO,Jane Smith,,2000000,Monthly 10,200000,1000000,1000000,15/02/2025,Default,,,,,,,,,\n"
            .",,TOTAL,,3200000,,,1700000,1500000,,,,,,,,,,,\n";
    }

    /**
     * The rows of a downloaded Excel file, read the way an upload is read.
     *
     * @return list<list<string>>
     */
    private function sheet(TestResponse $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());
        $rows = SpreadsheetReader::read($path, 'export.xlsx');
        unlink($path);

        return $rows;
    }

    private function upload(Employee $as, string $module, string $content, ?int $year = null, ?string $name = null): TestResponse
    {
        return $this->actingAs($as)->post('/api/v1/legacy-imports', array_filter([
            'module' => $module,
            'branch_id' => $this->branch->id,
            'year' => $year,
            'file' => UploadedFile::fake()->createWithContent($name ?? "{$module}.csv", $content),
        ]), ['Accept' => 'application/json']);
    }

    private function importAndApprove(string $module, string $content, ?int $year = null): int
    {
        $id = $this->upload($this->finance, $module, $content, $year)->assertCreated()->json('data.id');
        $this->actingAs($this->finance)->postJson("/api/v1/legacy-imports/{$id}/submit")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/legacy-imports/{$id}/approve")->assertOk();
        $this->actingAs($this->superAdmin);
        $this->assertSame(LegacyImport::STATUS_APPROVED, LegacyImport::findOrFail($id)->status);

        return $id;
    }

    private function assertIntegrityPasses(): void
    {
        $check = collect(app(LedgerIntegrity::class)->run($this->superAdmin->company_id)['checks'])->firstWhere('key', 'loan_receivable');
        $this->assertSame('pass', $check['status'], json_encode($check['details']));
    }
}
