<?php

namespace Tests\Feature\Api\Hrm;

use App\Enums\Account;
use App\Enums\TransactionType;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\BranchPeriodResult;
use App\Models\CommissionAllocation;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Services\Accounting\LedgerIntegrity;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\DividendService;
use App\Services\Hrm\CommissionEngine;
use App\Services\Hrm\PayrollEngine;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §21 / §22 / §49: commission of a closed month is paid through its own flow — Calculated → Awaiting Payment Request →
 * Payment Requested → Finance Approved → Paid — on a variable date, never as a payroll column.
 */
class CommissionPaymentApiTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private Employee $hr;

    private Employee $finance;

    private Employee $staffA;

    private Employee $staffB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-02 09:00:00'));
        $this->admin = $this->signInAdmin();
        HrmSetting::forCompany($this->admin->company_id)->update(['commission_pool_percent' => 10, 'zone_override_percent' => 0, 'staff_fund_percent' => 20]);
        $this->hr = $this->employeeWithRole('hr');
        $this->finance = $this->employeeWithRole('finance');
        $this->staffA = $this->staff(300000);
        $this->staffB = $this->staff(100000);
    }

    private function employeeWithRole(string $role): Employee
    {
        return Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }

    private function staff(int $salary): Employee
    {
        $employee = $this->employeeWithRole('loan_officer');
        $employee->salaryInfo()->create(['salary' => $salary, 'salary_type' => 'branch', 'commission_eligible' => true, 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);

        return $employee;
    }

    /**
     * A closed month with 1,000,000 distributable profit: pool 100,000 shared by salary — A 75,000, B 25,000.
     */
    private function closeAndCalculate(string $month, float $distributable = 1000000): AccountingPeriod
    {
        $start = CarbonImmutable::parse($month.'-01');
        $period = AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => $start->toDateString(), 'period_end' => $start->endOfMonth()->toDateString(), 'status' => AccountingPeriod::STATUS_CLOSED, 'closed_at' => $start->addMonth()->startOfMonth()]);
        BranchPeriodResult::create(['accounting_period_id' => $period->id, 'branch_id' => $this->admin->branch_id, 'net_profit' => $distributable, 'distributable_profit' => $distributable, 'commission_eligible' => true]);
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/commission/calculate', ['period' => $month])->assertOk();

        return $period;
    }

    private function fund(Account $account, float $amount, ?int $branchId = null): void
    {
        app(Ledger::class)->journal($this->admin->company_id, 'OPENING BALANCE '.$account->label(), [
            ['account' => $account, 'branch' => $branchId, 'debit' => $amount],
            ['account' => Account::Capital, 'credit' => $amount],
        ]);
    }

    private function balance(Account $account, ?int $branch = null): float
    {
        return app(Ledger::class)->balance($this->admin->company_id, $account, $branch);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Employee $staff, string $month = '2026-07', ?Employee $viewer = null): array
    {
        return collect($this->actingAs($viewer ?? $this->finance)->getJson("/api/v1/hrm/commission/payments?period={$month}")->assertOk()->json('data.rows'))->firstWhere('employee_id', $staff->id);
    }

    private function allocation(Employee $staff): CommissionAllocation
    {
        return CommissionAllocation::where('employee_id', $staff->id)->sole();
    }

    public function test_full_status_chain_records_dates_people_and_pays_on_a_variable_date_without_changing_the_period(): void
    {
        $this->closeAndCalculate('2026-07');

        // Calculated: HR and the staff member see the month's figures.
        $row = $this->row($this->staffA, viewer: $this->hr);
        $this->assertSame(['calculated', 'Calculated', '2026-07', '2026-08-01'], [$row['status'], $row['status_label'], $row['period'], $row['closing_date']]);
        $this->assertEquals([1000000, 0, 1000000, 100000, 0, 75000, 0, 75000], [$row['distributable_profit'], $row['offset_amount'], $row['commission_base'], $row['pool_amount'], $row['zone_allocation'], $row['staff_commission'], $row['negligence_deduction'], $row['net_commission']]);
        $mine = $this->actingAs($this->staffA)->getJson('/api/v1/hrm/commission/mine')->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->assertEquals(['2026-07', 'calculated', 75000], [$mine[0]['period'], $mine[0]['status'], $mine[0]['net_commission']]);
        foreach (['can_approve', 'distributable_profit', 'pool_amount', 'commission_base', 'offset_amount', 'paying_account'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $mine[0], $hidden);
        }
        $this->actingAs($this->staffA)->getJson('/api/v1/hrm/commission/payments?period=2026-07')->assertForbidden();

        // HR finalises: Awaiting Payment Request, and the month can no longer be recalculated.
        $this->actingAs($this->finance)->postJson('/api/v1/hrm/commission/payments/finalize', ['period' => '2026-07'])->assertForbidden();
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/commission/payments/finalize', ['period' => '2026-07'])->assertOk()->assertJsonPath('data.count', 2);
        $this->assertSame([CommissionAllocation::STATUS_AWAITING_REQUEST, $this->hr->id], [$this->allocation($this->staffA)->payment_status, $this->allocation($this->staffA)->finalized_by]);
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/commission/calculate', ['period' => '2026-07'])->assertUnprocessable()->assertJsonPath('errors.period.0', CommissionEngine::LOCKED_BY_PAYMENT_MESSAGE);
        $this->assertSame(CommissionEngine::STATUS_LOCKED_IN_PAYMENT, app(CommissionEngine::class)->report($this->admin->company_id, CarbonImmutable::parse('2026-07-01'))['allocation_status']);

        // HR requests payment of A only.
        $idA = $this->allocation($this->staffA)->id;
        $idB = $this->allocation($this->staffB)->id;
        $this->actingAs($this->finance)->postJson('/api/v1/hrm/commission/payments/request', ['ids' => [$idA]])->assertForbidden();
        $this->travelTo(CarbonImmutable::parse('2026-08-03 10:00:00'));
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/commission/payments/request', ['ids' => [$idA]])->assertOk();
        $this->assertSame([CommissionAllocation::STATUS_REQUESTED, CommissionAllocation::STATUS_AWAITING_REQUEST], [$this->allocation($this->staffA)->payment_status, $this->allocation($this->staffB)->payment_status]);

        // HR cannot approve: no Finance permission, and even with it rule 6 blocks the requester.
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/commission/payments/approve', ['ids' => [$idA]])->assertForbidden();
        $this->hr->permissionOverrides()->create(['permission' => 'payroll.pay', 'granted' => true]);
        $this->actingAs($this->hr->fresh())->postJson('/api/v1/hrm/commission/payments/approve', ['ids' => [$idA]])->assertForbidden()->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);
        $this->assertFalse($this->row($this->staffA, viewer: $this->hr->fresh())['can_approve']);
        $this->assertTrue($this->row($this->staffA)['can_approve']);

        // Finance cannot pay before approving; approves the requested commission of the month (bulk).
        $this->actingAs($this->finance)->postJson('/api/v1/hrm/commission/payments/pay', ['ids' => [$idA], 'ac_id' => 'interest'])->assertUnprocessable();
        $this->travelTo(CarbonImmutable::parse('2026-08-04 11:00:00'));
        $this->actingAs($this->finance)->postJson('/api/v1/hrm/commission/payments/approve', ['period' => '2026-07'])->assertOk()->assertJsonPath('data.count', 1);

        // B: requested, rejected back to awaiting with a reason, requested again and approved.
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/commission/payments/request', ['period' => '2026-07'])->assertOk()->assertJsonPath('data.count', 1);
        $this->actingAs($this->finance)->postJson('/api/v1/hrm/commission/payments/reject', ['ids' => [$idB]])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->finance)->postJson('/api/v1/hrm/commission/payments/reject', ['ids' => [$idB], 'reason' => 'Attendance to confirm'])->assertOk();
        $rejected = $this->row($this->staffB);
        $this->assertSame(['awaiting_request', $this->finance->full_name, 'Attendance to confirm', null], [$rejected['status'], $rejected['rejected_by'], $rejected['rejection_reason'], $rejected['requested_by']]);
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/commission/payments/request', ['ids' => [$idB]])->assertOk();
        $this->actingAs($this->finance)->postJson('/api/v1/hrm/commission/payments/approve', ['ids' => [$idB]])->assertOk();

        // Paid on 6 August from the branch INTEREST A/C (recorded on the 7th): the commission period stays July.
        $this->fund(Account::Interest, 150000, $this->admin->branch_id);
        $this->travelTo(CarbonImmutable::parse('2026-08-07 15:00:00'));
        $this->actingAs($this->finance)->postJson('/api/v1/hrm/commission/payments/pay', ['period' => '2026-07', 'ac_id' => 'interest', 'paid_on' => '2026-08-08'])->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        $this->actingAs($this->finance)->postJson('/api/v1/hrm/commission/payments/pay', ['period' => '2026-07', 'ac_id' => 'interest', 'paid_on' => '2026-08-06'])->assertOk()->assertJsonPath('data.count', 2);

        $paid = $this->row($this->staffA);
        $this->assertSame(['paid', 'Paid', '2026-07', '2026-08-06', 'INTEREST A/C'], [$paid['status'], $paid['status_label'], $paid['period'], $paid['paid_on'], $paid['paying_account']]);
        $this->assertSame([$this->hr->full_name, '2026-08-03 10:00:00', $this->finance->full_name, '2026-08-04 11:00:00', $this->finance->full_name, '2026-08-07 15:00:00'], [$paid['requested_by'], $paid['requested_at'], $paid['approved_by'], $paid['approved_at'], $paid['paid_by'], $paid['paid_at']]);
        $this->assertEquals([75000, 0, 75000], [$paid['calculated_amount'], $paid['negligence_deduction'], $paid['net_commission']]);

        $entry = JournalEntry::findOrFail($this->allocation($this->staffA)->payment_journal_entry_id);
        $this->assertSame(['2026-08-06', TransactionType::CommissionPayment], [$entry->entry_date->toDateString(), $entry->transaction_type]);
        $this->assertSame($paid['journal_reference'], $entry->reference);
        $this->assertEquals(0, $this->balance(Account::CommissionPayable, $this->admin->branch_id));
        $this->assertEquals(50000, $this->balance(Account::Interest, $this->admin->branch_id));
        $this->assertEquals(-100000, $this->balance(Account::RetainedProfit, $this->admin->branch_id), 'payment never touches the profit account');
        $this->assertEquals([100000, 100000], [$this->row($this->staffA)['calculated_amount'] + $this->row($this->staffB)['calculated_amount'], $this->actingAs($this->finance)->getJson('/api/v1/hrm/commission/payments?period=2026-07')->json('data.summary.total_paid')]);
        $this->actingAs($this->finance)->postJson('/api/v1/hrm/commission/payments/pay', ['ids' => [$idA], 'ac_id' => 'interest'])->assertUnprocessable();

        $this->assertSame(
            ['CommissionAllocation.finalised', 'CommissionAllocation.payment_requested', 'CommissionAllocation.approved', 'CommissionAllocation.paid'],
            AuditLog::where('auditable_type', (new CommissionAllocation)->getMorphClass())->where('auditable_id', $idA)->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame('paid', $this->actingAs($this->staffA)->getJson('/api/v1/hrm/commission/mine')->json('data.0.status'));
    }

    public function test_super_admin_may_approve_his_own_request_and_payment_needs_funds_and_a_date_after_the_period(): void
    {
        $this->closeAndCalculate('2026-07');
        $this->actingAs($this->admin)->postJson('/api/v1/hrm/commission/payments/request', ['period' => '2026-07'])->assertOk();
        $this->actingAs($this->admin)->postJson('/api/v1/hrm/commission/payments/approve', ['period' => '2026-07'])->assertOk()->assertJsonPath('data.count', 2);

        // The staff member receiving the commission can never approve or pay it, even holding Finance permissions.
        $this->staffA->permissionOverrides()->create(['permission' => 'payroll.pay', 'granted' => true]);
        $this->actingAs($this->staffA->fresh())->postJson('/api/v1/hrm/commission/payments/pay', ['ids' => [$this->allocation($this->staffA)->id], 'ac_id' => 'company'])->assertForbidden();

        $available = app(DividendService::class)->availableProfit($this->admin->company_id, CarbonImmutable::parse('2026-07-01'));

        $this->actingAs($this->finance)->postJson('/api/v1/hrm/commission/payments/pay', ['period' => '2026-07', 'ac_id' => 'company'])->assertUnprocessable()->assertJsonValidationErrors('ac_id');
        $this->fund(Account::Company, 1000000);
        $this->actingAs($this->finance)->postJson('/api/v1/hrm/commission/payments/pay', ['period' => '2026-07', 'ac_id' => 'company', 'paid_on' => '2026-07-31'])->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        $this->assertSame(0, CommissionAllocation::where('payment_status', CommissionAllocation::STATUS_PAID)->count(), 'a refused payment posts nothing');
        $this->assertSame(0, JournalEntry::where('transaction_type', TransactionType::CommissionPayment->value)->count());

        $this->actingAs($this->finance)->postJson('/api/v1/hrm/commission/payments/pay', ['period' => '2026-07', 'ac_id' => 'company'])->assertOk();
        $this->assertEquals(900000, $this->balance(Account::Company));

        // Dividend availability is based on CALCULATED commission: paying it changes nothing.
        $this->assertSame($available, app(DividendService::class)->availableProfit($this->admin->company_id, CarbonImmutable::parse('2026-07-01')));
        $this->assertEquals(100000, $available['commission_amount']);
        $checks = collect(app(LedgerIntegrity::class)->run($this->admin->company_id)['checks'])->keyBy('key');
        $this->assertSame('pass', $checks['commission_payable']['status']);
    }

    public function test_commission_is_not_in_new_payroll_runs_and_legacy_payroll_commission_stays_readable_and_out_of_the_flow(): void
    {
        $companyId = $this->admin->company_id;
        $engine = app(PayrollEngine::class);

        // LEGACY July: an approved payroll run already carried the commission (allocations linked, migrated to `payroll`).
        $july = $this->closeAndCalculate('2026-07');
        $run = PayrollRun::create(['company_id' => $companyId, 'period' => '2026-07-01', 'status' => PayrollRun::STATUS_APPROVED, 'prepared_by' => $this->hr->id]);
        foreach ($engine->preview($companyId, CarbonImmutable::parse('2026-07-01')) as $line) {
            $commission = (float) CommissionAllocation::where('employee_id', $line['employee_id'])->value('amount');
            $run->items()->create(['commission' => $commission, 'gross' => $line['gross'] + $commission, 'take_home' => $line['take_home'] + $commission] + collect($line)->except(['employee', 'branch', 'allowance_ids', 'negligence_outstanding', 'net_commission'])->all());
        }
        CommissionAllocation::where('accounting_period_id', $july->id)->update(['payroll_run_id' => $run->id]);
        (require database_path('migrations/2026_09_16_225846_add_payment_workflow_to_commission_allocations.php'))->backfill();

        $legacy = $this->row($this->staffA);
        $this->assertSame(['payroll', 'In Payroll (legacy)', $run->id], [$legacy['status'], $legacy['status_label'], $legacy['payroll_run_id']]);
        $this->assertEquals(75000, collect($this->actingAs($this->finance)->getJson('/api/v1/hrm/payroll?period=2026-07')->assertOk()->json('data.rows'))->firstWhere('employee_id', $this->staffA->id)['commission']);
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/commission/payments/request', ['ids' => [$this->allocation($this->staffA)->id]])->assertUnprocessable();
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/commission/payments/request', ['period' => '2026-07'])->assertUnprocessable();
        $this->assertSame(CommissionEngine::LOCKED_MESSAGE, app(CommissionEngine::class)->lockReason($july));

        // August: a new payroll carries no commission; a legacy DRAFT that still did releases its allocations when regenerated.
        $this->travelTo(CarbonImmutable::parse('2026-09-02 09:00:00'));
        $august = $this->closeAndCalculate('2026-08', 2000000);
        $draft = PayrollRun::create(['company_id' => $companyId, 'period' => '2026-08-01', 'status' => PayrollRun::STATUS_DRAFT, 'prepared_by' => $this->hr->id]);
        $draft->items()->create(['commission' => 150000, 'gross' => 450000, 'take_home' => 390000] + collect($engine->preview($companyId, CarbonImmutable::parse('2026-08-01'))->firstWhere('employee_id', $this->staffA->id))->except(['employee', 'branch', 'allowance_ids', 'negligence_outstanding', 'net_commission'])->all());

        // Not linked to any allocation: the stale commission line cannot be approved.
        $this->actingAs($this->finance)->postJson("/api/v1/hrm/payroll/{$draft->id}/approve")->assertUnprocessable()->assertJsonValidationErrors('status');

        CommissionAllocation::where('accounting_period_id', $august->id)->update(['payroll_run_id' => $draft->id, 'payment_status' => CommissionAllocation::STATUS_PAYROLL]);
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/payroll/generate', ['period' => '2026-08'])->assertOk();
        $this->assertEquals(0, (float) $draft->fresh()->items()->sum('commission'));
        $this->assertSame([CommissionAllocation::STATUS_CALCULATED], CommissionAllocation::where('accounting_period_id', $august->id)->distinct()->pluck('payment_status')->all());
        $this->assertSame(0, CommissionAllocation::where('accounting_period_id', $august->id)->whereNotNull('payroll_run_id')->count());

        $this->actingAs($this->finance)->postJson("/api/v1/hrm/payroll/{$draft->id}/approve")->assertOk();
        $this->assertEquals(100000 + 200000, $this->balance(Account::CommissionPayable, $this->admin->branch_id), 'payroll approval leaves August commission payable (July was legacy, booked outside this test)');
        $this->assertSame(0, CommissionAllocation::where('accounting_period_id', $july->id)->where('payment_status', '!=', CommissionAllocation::STATUS_PAYROLL)->count(), 'July history is untouched');
    }
}
