<?php

namespace Tests\Feature\Api\Hrm;

use App\Enums\Account;
use App\Models\AccountingPeriod;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\SalaryPayment;
use App\Models\StaffAllowance;
use App\Models\StaffDeduction;
use App\Services\Ledger;
use App\Services\PeriodClose;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §20: payroll is an expense of its PERIOD. A July payroll approved and paid in August is still a July expense; the payment
 * keeps its real date.
 */
class PayrollPeriodExpenseTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private Employee $hr;

    private Employee $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-02 10:00:00'));
        $this->admin = $this->signInAdmin();
        HrmSetting::forCompany($this->admin->company_id)->update(['staff_fund_percent' => 20]);
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

    private function staff(int $salary): Employee
    {
        $employee = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id]);
        $employee->salaryInfo()->create(['salary' => $salary, 'salary_type' => 'branch', 'commission_eligible' => true, 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);

        return $employee;
    }

    private function generate(string $month): PayrollRun
    {
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/payroll/generate', ['period' => $month])->assertOk();

        return PayrollRun::whereDate('period', $month.'-01')->sole();
    }

    private function salaryExpense(string $from, string $to): float
    {
        return app(Ledger::class)->movement($this->admin->company_id, Account::SalaryExpense, CarbonImmutable::parse($from), CarbonImmutable::parse($to), true, $this->admin->branch_id)
            - app(Ledger::class)->movement($this->admin->company_id, Account::SalaryExpense, CarbonImmutable::parse($from), CarbonImmutable::parse($to), false, $this->admin->branch_id);
    }

    public function test_a_july_payroll_approved_and_paid_in_august_stays_a_july_expense(): void
    {
        $staff = $this->staff(500000);
        StaffAllowance::create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'employee_id' => $staff->id, 'amount' => 100000, 'status' => StaffAllowance::STATUS_ACTIVE, 'recurring' => true]);
        StaffDeduction::create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'employee_id' => $staff->id, 'amount' => 30000, 'instalments' => 1, 'instalment_amount' => 30000]);

        $run = $this->generate('2026-07');
        $this->actingAs($this->admin)->getJson('/api/v1/hrm/payroll?period=2026-07')->assertOk()
            ->assertJsonPath('data.expense_date', '2026-07-31')
            ->assertJsonPath('data.expense_period_note', null);

        $this->actingAs($this->admin)->postJson("/api/v1/hrm/payroll/{$run->id}/approve")->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-08-05 11:00:00'));
        $this->fundPayroll($this->admin);
        $this->actingAs($this->finance)->postJson("/api/v1/hrm/payroll/{$run->id}/pay", ['ac_id' => 'interest'])->assertOk();

        $run->refresh();
        $this->assertSame('2026-07-31', $run->expense_date->toDateString());
        $this->assertNull($run->expense_period_note);
        $this->assertSame('2026-08-02', $run->approved_at->toDateString());
        $this->assertSame('2026-08-05', $run->paid_at->toDateString());
        $this->assertSame('2026-08-05', SalaryPayment::where('payroll_run_id', $run->id)->sole()->paid_on->toDateString());

        $recognition = JournalEntry::where('source_type', $run->getMorphClass())->where('source_id', $run->id)->sole();
        $this->assertSame('2026-07-31', $recognition->entry_date->toDateString());
        $payment = SalaryPayment::where('payroll_run_id', $run->id)->sole();
        $dates = JournalEntry::where('source_type', $payment->getMorphClass())->where('source_id', $payment->id)->orderBy('id')->get()
            ->mapWithKeys(fn (JournalEntry $entry): array => [implode(' ', array_slice(explode(' ', $entry->description), 0, 2)) => $entry->entry_date->toDateString()])->all();
        $this->assertSame(['Salary payment' => '2026-08-05', 'Salary deductions' => '2026-07-31'], $dates);

        // Salary 500,000 − other deduction 30,000 = 470,000 (no company contribution on top, ruling 2026-09-17) of July salary expense,
        // allowance 100,000 in July; nothing lands in August.
        $this->assertEquals(470000, $this->salaryExpense('2026-07-01', '2026-07-31'));
        $this->assertEquals(0, $this->salaryExpense('2026-08-01', '2026-08-31'));

        // The July month-end sees the payroll: expenses = 470,000 salary + 100,000 allowance.
        $period = app(PeriodClose::class)->calculate($this->admin->company_id, CarbonImmutable::parse('2026-07-01'));
        $this->assertEquals(570000, (float) $period->results()->where('branch_id', $this->admin->branch_id)->value('expenses'));
    }

    public function test_a_payroll_approved_during_its_month_is_dated_on_the_approval_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-24 09:00:00'));
        $this->staff(300000);
        $run = $this->generate('2026-07');

        $this->actingAs($this->admin)->postJson("/api/v1/hrm/payroll/{$run->id}/approve")->assertOk();

        $this->assertSame('2026-07-24', $run->fresh()->expense_date->toDateString());
        $this->assertSame('2026-07-24', JournalEntry::where('source_type', $run->getMorphClass())->where('source_id', $run->id)->sole()->entry_date->toDateString());
    }

    public function test_a_payroll_of_an_already_closed_month_records_why_its_expense_is_dated_later(): void
    {
        $this->staff(300000);
        AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'status' => AccountingPeriod::STATUS_CLOSED]);
        $run = $this->generate('2026-07');

        $note = 'The July 2026 accounting period was already closed, so this payroll\'s expense could not be dated inside it and was recognised on 2026-08-02.';
        $this->actingAs($this->admin)->getJson('/api/v1/hrm/payroll?period=2026-07')->assertOk()->assertJsonPath('data.expense_period_note', $note);

        $this->actingAs($this->admin)->postJson("/api/v1/hrm/payroll/{$run->id}/approve")->assertOk();

        $run->refresh();
        $this->assertSame('2026-08-02', $run->expense_date->toDateString());
        $this->assertSame($note, $run->expense_period_note);
        $this->assertSame(0, JournalEntry::whereBetween('entry_date', ['2026-07-01', '2026-07-31'])->count(), 'closed history is never rewritten');
        $this->actingAs($this->admin)->getJson('/api/v1/hrm/payroll?period=2026-07')->assertOk()
            ->assertJsonPath('data.run.expense_date', '2026-08-02')
            ->assertJsonPath('data.run.expense_period_note', $note);
    }
}
