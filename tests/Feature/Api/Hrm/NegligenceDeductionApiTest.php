<?php

namespace Tests\Feature\Api\Hrm;

use App\Enums\Account;
use App\Models\AccountingPeriod;
use App\Models\BranchPeriodResult;
use App\Models\CommissionAllocation;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\NegligenceDeduction;
use App\Models\PayrollRun;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §23 + §57: negligence is approved by Finance and recovered from COMMISSION PAYMENTS only (spec §22 flow, never from
 * payroll/salary), into the PRINCIPAL A/C, with any balance carried forward to the next commission.
 */
class NegligenceDeductionApiTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private Employee $hr;

    private Employee $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-02 09:00:00'));
        $this->admin = $this->signInAdmin();
        // Staff take the whole pool (no zone manager carve-out) so the commission equals 10 % of the distributable profit.
        HrmSetting::forCompany($this->admin->company_id)->update(['commission_pool_percent' => 10, 'zone_override_percent' => 0, 'staff_fund_percent' => 20]);
        $this->hr = $this->employeeWithRole('hr');
        $this->finance = $this->employeeWithRole('finance');
    }

    private function employeeWithRole(string $role): Employee
    {
        return Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }

    private function staff(int $salary, string $type = 'branch'): Employee
    {
        $employee = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id]);
        $employee->salaryInfo()->create(['salary' => $salary, 'salary_type' => $type, 'commission_eligible' => $type !== 'hq', 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);

        return $employee;
    }

    private function balance(Account $account, ?int $branch = null): float
    {
        return app(Ledger::class)->balance($this->admin->company_id, $account, $branch);
    }

    private function closeMonth(string $month, float $distributable): void
    {
        $start = CarbonImmutable::parse($month.'-01');
        $period = AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => $start->toDateString(), 'period_end' => $start->endOfMonth()->toDateString(), 'status' => AccountingPeriod::STATUS_CLOSED]);
        BranchPeriodResult::create(['accounting_period_id' => $period->id, 'branch_id' => $this->admin->branch_id, 'net_profit' => $distributable, 'distributable_profit' => $distributable, 'commission_eligible' => true]);
    }

    /**
     * HR generates, the Super Admin approves (not the preparer), Finance pays.
     */
    private function runPayroll(string $month): PayrollRun
    {
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/payroll/generate', ['period' => $month])->assertOk();
        $run = PayrollRun::whereDate('period', $month.'-01')->sole();
        $this->actingAs($this->admin)->postJson("/api/v1/hrm/payroll/{$run->id}/approve")->assertOk();
        $this->actingAs($this->finance)->postJson("/api/v1/hrm/payroll/{$run->id}/pay", ['ac_id' => 'interest'])->assertOk();

        return $run->fresh();
    }

    public function test_hr_creates_and_only_finance_approves_with_segregation_of_duties(): void
    {
        $staff = $this->staff(500000);

        $this->actingAs($this->finance)->postJson('/api/v1/hrm/negligence-deductions', ['employee_id' => $staff->id, 'amount' => 300000, 'reason' => 'Cash shortage'])->assertForbidden();
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/negligence-deductions', ['employee_id' => $staff->id, 'reason' => 'Cash shortage'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $id = $this->actingAs($this->hr)->postJson('/api/v1/hrm/negligence-deductions', ['employee_id' => $staff->id, 'amount' => 300000, 'reason' => 'Cash shortage'])
            ->assertCreated()->assertJsonPath('data.status', NegligenceDeduction::STATUS_PENDING)->json('data.id');

        // HR cannot approve; an HR officer who also holds payroll.pay still cannot approve what they created (rule 6).
        $this->actingAs($this->hr)->postJson("/api/v1/hrm/negligence-deductions/{$id}/approve")->assertForbidden();
        $this->hr->permissionOverrides()->create(['permission' => 'payroll.pay', 'granted' => true]);
        $this->actingAs($this->hr->fresh())->postJson("/api/v1/hrm/negligence-deductions/{$id}/approve")->assertForbidden()->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);

        $row = collect($this->actingAs($this->finance)->getJson('/api/v1/hrm/negligence-deductions')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertTrue($row['can_approve']);
        $this->assertEquals(300000, $row['outstanding_amount']);

        $this->actingAs($this->finance)->postJson("/api/v1/hrm/negligence-deductions/{$id}/approve")->assertOk();
        $this->actingAs($this->finance)->postJson("/api/v1/hrm/negligence-deductions/{$id}/approve")->assertUnprocessable();
        $deduction = NegligenceDeduction::findOrFail($id);
        $this->assertSame(NegligenceDeduction::STATUS_APPROVED, $deduction->status);
        $this->assertSame($this->finance->id, $deduction->approved_by);

        // Rejection needs a reason; the Super Admin may approve (or reject) even what he created himself.
        $own = $this->actingAs($this->admin)->postJson('/api/v1/hrm/negligence-deductions', ['employee_id' => $staff->id, 'amount' => 5000, 'reason' => 'Lost receipt book'])->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/v1/hrm/negligence-deductions/{$own}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->admin)->postJson("/api/v1/hrm/negligence-deductions/{$own}/reject", ['reason' => 'Duplicate'])->assertOk();
        $this->assertSame(NegligenceDeduction::STATUS_REJECTED, NegligenceDeduction::findOrFail($own)->status);
        $this->assertFalse(NegligenceDeduction::recoverable()->whereKey($own)->exists());
    }

    /**
     * Spec §21 / §22 commission payment flow: HR requests the month's commission, Finance approves and pays it from the branch
     * INTEREST A/C on the given date.
     */
    private function payCommission(string $month, string $paidOn): void
    {
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/commission/payments/request', ['period' => $month])->assertOk();
        $this->actingAs($this->finance)->postJson('/api/v1/hrm/commission/payments/approve', ['period' => $month])->assertOk();
        $this->actingAs($this->finance)->postJson('/api/v1/hrm/commission/payments/pay', ['period' => $month, 'ac_id' => 'interest', 'paid_on' => $paidOn])->assertOk();
    }

    private function fundInterest(float $amount): void
    {
        app(Ledger::class)->journal($this->admin->company_id, 'OPENING BALANCE interest', [
            ['account' => Account::Interest, 'branch' => $this->admin->branch_id, 'debit' => $amount],
            ['account' => Account::Capital, 'credit' => $amount],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function commissionRow(string $month, int $employeeId): array
    {
        return collect($this->actingAs($this->finance)->getJson("/api/v1/hrm/commission/payments?period={$month}")->assertOk()->json('data.rows'))->firstWhere('employee_id', $employeeId);
    }

    public function test_section_57_negligence_is_recovered_from_commission_payments_only_and_carried_forward(): void
    {
        $staff = $this->staff(500000);
        $branchId = $this->admin->branch_id;
        $this->fundInterest(2000000);

        $id = $this->actingAs($this->hr)->postJson('/api/v1/hrm/negligence-deductions', ['employee_id' => $staff->id, 'amount' => 300000, 'reason' => 'Loan disbursed without collateral'])->json('data.id');

        // Pending negligence is never deducted: the July commission shows no deduction until Finance approves.
        $this->closeMonth('2026-07', 1800000);
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/commission/calculate', ['period' => '2026-07'])->assertOk();
        $row = $this->commissionRow('2026-07', $staff->id);
        $this->assertEquals([180000, 0, 180000], [$row['calculated_amount'], $row['negligence_deduction'], $row['net_commission']]);

        $this->actingAs($this->finance)->postJson("/api/v1/hrm/negligence-deductions/{$id}/approve")->assertOk();
        $row = $this->commissionRow('2026-07', $staff->id);
        $this->assertEquals([180000, 180000, 0], [$row['calculated_amount'], $row['negligence_deduction'], $row['net_commission']]);
        $this->assertTrue($row['negligence_expected']);

        // Salary is not touched: the July payroll has no commission and no negligence — take home = 500,000 − 100,000 fund.
        $july = $this->runPayroll('2026-07');
        $item = $july->items()->where('employee_id', $staff->id)->sole();
        $this->assertEquals([0, 0, 400000], [(float) $item->commission, (float) $item->negligence, (float) $item->take_home]);
        $this->assertEquals(300000, NegligenceDeduction::findOrFail($id)->outstandingAmount());

        // July commission paid: 180,000 → recovery 180,000, net commission 0, outstanding 120,000.
        $this->payCommission('2026-07', '2026-08-02');
        $row = $this->commissionRow('2026-07', $staff->id);
        $this->assertEquals([180000, 180000, 0], [$row['calculated_amount'], $row['negligence_deduction'], $row['net_commission']]);
        $this->assertSame(CommissionAllocation::STATUS_PAID, $row['status']);
        $this->assertFalse($row['negligence_expected']);

        $deduction = NegligenceDeduction::findOrFail($id);
        $this->assertSame(NegligenceDeduction::STATUS_RECOVERING, $deduction->status);
        $this->assertEquals(120000, $deduction->outstandingAmount());

        // The recovery goes to the PRINCIPAL A/C, never to the Staff Fund: fund cash holds only the 100,000 contribution.
        $this->assertEquals(180000, $this->balance(Account::Principal));
        $this->assertEquals(-180000, $this->balance(Account::WriteOffExpense, $branchId));
        $this->assertEquals(100000, $this->balance(Account::StaffFundCash));
        $this->assertEquals(2000000 - (400000 + 100000) - 180000, $this->balance(Account::Interest, $branchId));
        $this->assertEquals(0, $this->balance(Account::StaffPayable));
        $this->assertEquals(0, $this->balance(Account::CommissionPayable, $branchId));

        // August: commission 250,000 → the remaining 120,000 is recovered, staff receive 130,000 commission.
        $this->travelTo(CarbonImmutable::parse('2026-09-02 09:00:00'));
        $this->closeMonth('2026-08', 2500000);
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/commission/calculate', ['period' => '2026-08'])->assertOk();
        $this->assertEquals(500000 - 100000, (float) $this->runPayroll('2026-08')->items()->where('employee_id', $staff->id)->value('take_home'));
        $this->payCommission('2026-08', '2026-09-01');
        $row = $this->commissionRow('2026-08', $staff->id);
        $this->assertEquals([250000, 120000, 130000], [$row['calculated_amount'], $row['negligence_deduction'], $row['net_commission']]);

        $deduction->refresh();
        $this->assertSame(NegligenceDeduction::STATUS_RECOVERED, $deduction->status);
        $this->assertEquals(0, $deduction->outstandingAmount());
        $this->assertEquals(300000, $this->balance(Account::Principal));

        $row = collect($this->actingAs($this->finance)->getJson('/api/v1/hrm/negligence-deductions')->json('data'))->firstWhere('id', $id);
        $this->assertSame([
            ['period' => '2026-07', 'commission' => 180000, 'amount' => 180000, 'outstanding_after' => 120000],
            ['period' => '2026-08', 'commission' => 250000, 'amount' => 120000, 'outstanding_after' => 0],
        ], array_map(fn (array $recovery): array => collect($recovery)->only(['period', 'commission', 'amount', 'outstanding_after'])->map(fn ($value) => is_float($value) ? (int) $value : $value)->all(), $row['recoveries']));
        $this->assertNotNull($row['recoveries'][0]['commission_allocation_id']);

        // September: nothing left to recover.
        $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00:00'));
        $this->closeMonth('2026-09', 1000000);
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/commission/calculate', ['period' => '2026-09'])->assertOk();
        $this->payCommission('2026-09', '2026-10-02');
        $row = $this->commissionRow('2026-09', $staff->id);
        $this->assertEquals([100000, 0, 100000], [$row['calculated_amount'], $row['negligence_deduction'], $row['net_commission']]);
    }

    public function test_negligence_never_reduces_salary_when_there_is_no_commission(): void
    {
        $hq = $this->staff(400000, 'hq');
        $id = $this->actingAs($this->hr)->postJson('/api/v1/hrm/negligence-deductions', ['employee_id' => $hq->id, 'amount' => 50000, 'reason' => 'Damaged laptop'])->json('data.id');
        $this->actingAs($this->finance)->postJson("/api/v1/hrm/negligence-deductions/{$id}/approve")->assertOk();

        // HQ staff earn no commission, so nothing is recovered and the whole balance carries forward.
        $this->fundPayroll($this->admin);
        $run = $this->runPayroll('2026-07');
        $item = $run->items()->where('employee_id', $hq->id)->sole();
        $this->assertEquals([0, 0, 320000], [(float) $item->commission, (float) $item->negligence, (float) $item->take_home]);
        $this->assertEquals(50000, NegligenceDeduction::findOrFail($id)->outstandingAmount());
        $this->assertSame(NegligenceDeduction::STATUS_APPROVED, NegligenceDeduction::findOrFail($id)->status);
        $this->assertEquals(0, $this->balance(Account::Principal));
    }
}
