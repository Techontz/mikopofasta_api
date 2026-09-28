<?php

namespace Tests\Feature\Api\Accounting;

use App\Enums\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\BranchPeriodResult;
use App\Models\JournalEntry;
use App\Services\Ledger;
use App\Services\PeriodClose;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class PeriodCloseApiTest extends TestCase
{
    use AccountingTestHelpers, RefreshDatabase;
    use UsesSecondApprover;

    public function test_monthly_profit_is_calculated_per_branch_with_reserve_cut_hq_hold_and_eligibility(): void
    {
        $admin = $this->signInAdmin();
        $this->postIncome($admin->company_id, $admin->branch_id, 100000, 10000, 20000, 5000, '2026-08-10');
        $this->postExpense($admin->company_id, $admin->branch_id, 15000, '2026-08-20');
        $this->postIncome($admin->company_id, $admin->branch_id, 999999, 0, 0, 0, '2026-09-01');

        $response = $this->postJson('/api/v1/accounting/periods', ['month' => '2026-08'])
            ->assertOk()->assertJsonPath('message', 'Profit Calculated successfully')->assertJsonPath('data.status', 'open');

        $row = $response->json('data.results.0');
        $this->assertEquals(90000, $row['interest_income']);
        $this->assertEquals(10000, $row['reserve_amount']);
        $this->assertEquals(20000, $row['fee_income']);
        $this->assertEquals(5000, $row['penalty_income']);
        $this->assertEquals(115000, $row['total_income']);
        $this->assertEquals(15000, $row['expenses']);
        $this->assertEquals(100000, $row['gross_profit']);
        $this->assertEquals(0, $row['loss_brought_forward']);
        $this->assertEquals(100000, $row['net_profit']);
        $this->assertEquals(2000, $row['hq_hold_amount']);
        $this->assertEquals(98000, $row['distributable_profit']);
        $this->assertTrue($row['commission_eligible']);

        $this->assertDatabaseHas('branch_period_results', ['branch_id' => $admin->branch_id, 'distributable_profit' => 98000, 'commission_eligible' => true]);
        $this->assertEquals(100000, app(PeriodClose::class)->companyProfit($admin->company_id, CarbonImmutable::parse('2026-08-15')));
    }

    public function test_loss_is_carried_forward_and_blocks_commission_until_recovered(): void
    {
        $admin = $this->signInAdmin();
        $this->postIncome($admin->company_id, $admin->branch_id, 10000, 0, 0, 0, '2026-07-05');
        $this->postExpense($admin->company_id, $admin->branch_id, 40000, '2026-07-06');
        $this->postIncome($admin->company_id, $admin->branch_id, 50000, 0, 0, 0, '2026-08-05');

        $july = $this->postJson('/api/v1/accounting/periods', ['month' => '2026-07'])->assertOk()->json('data.results.0');
        $this->assertEquals(-30000, $july['net_profit']);
        $this->assertEquals(30000, $july['loss_carried_forward']);
        $this->assertEquals(0, $july['hq_hold_amount']);
        $this->assertFalse($july['commission_eligible']);

        $august = $this->postJson('/api/v1/accounting/periods', ['month' => '2026-08'])->assertOk()->json('data.results.0');
        $this->assertEquals(50000, $august['gross_profit']);
        $this->assertEquals(30000, $august['loss_brought_forward']);
        $this->assertEquals(20000, $august['net_profit']);
        $this->assertEquals(0, $august['loss_carried_forward']);
        $this->assertEquals(400, $august['hq_hold_amount']);
        $this->assertEquals(19600, $august['distributable_profit']);
        $this->assertTrue($august['commission_eligible']);
    }

    public function test_closing_posts_closing_entries_moves_hq_hold_and_locks_the_period(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        $this->postIncome($admin->company_id, $admin->branch_id, 100000, 10000, 0, 0, '2026-08-10');
        $this->postExpense($admin->company_id, $admin->branch_id, 20000, '2026-08-20');
        $period = AccountingPeriod::findOrFail($this->postJson('/api/v1/accounting/periods', ['month' => '2026-08'])->json('data.id'));

        $this->postJson("/api/v1/accounting/periods/{$period->id}/close")
            ->assertOk()->assertJsonPath('message', 'Period Closed successfully')->assertJsonPath('data.status', 'closed');

        $until = CarbonImmutable::parse('2026-08-31');
        $this->assertSame(0.0, $ledger->balance($admin->company_id, Account::InterestIncome, $admin->branch_id, until: $until));
        $this->assertSame(0.0, $ledger->balance($admin->company_id, Account::OperatingExpense, allBranches: true, until: $until));
        // D6: the legacy reserve (10,000) is closed to INTEREST RESERVE, not profit; branch profit 70,000 less the HQ 2% hold.
        $this->assertSame(68600.0, $ledger->balance($admin->company_id, Account::RetainedProfit, $admin->branch_id));
        $this->assertSame(10000.0, $ledger->balance($admin->company_id, Account::InterestReserve, $admin->branch_id));
        $this->assertSame(1400.0, $ledger->balance($admin->company_id, Account::RetainedProfit));
        $this->assertSame($admin->id, $period->fresh()->closed_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AccountingPeriod.closed', 'auditable_id' => $period->id]);

        $this->expectException(ValidationException::class);
        $this->postExpense($admin->company_id, $admin->branch_id, 500, '2026-08-25');
    }

    public function test_closed_period_entries_are_reversed_on_todays_date_and_cannot_be_recalculated(): void
    {
        $admin = $this->signInAdmin();
        $this->postExpense($admin->company_id, $admin->branch_id, 20000, '2026-08-20');
        $entry = JournalEntry::firstOrFail();
        $period = app(PeriodClose::class)->calculate($admin->company_id, CarbonImmutable::parse('2026-08-01'));
        app(PeriodClose::class)->close($period, $admin);

        $this->approveAsSecondUser($admin, "/api/v1/accounting/journal/{$entry->id}/reverse", ['reason' => 'Wrong amount']);
        $this->assertSame(now()->toDateString(), JournalEntry::where('reversal_of_id', $entry->id)->firstOrFail()->entry_date->toDateString());

        $this->postJson('/api/v1/accounting/periods', ['month' => '2026-08'])->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->postJson("/api/v1/accounting/periods/{$period->id}/close")->assertUnprocessable();
    }

    public function test_close_rules_current_month_and_earlier_open_periods(): void
    {
        $admin = $this->signInAdmin();
        $current = app(PeriodClose::class)->calculate($admin->company_id, now());
        $this->postJson("/api/v1/accounting/periods/{$current->id}/close")->assertUnprocessable()->assertJsonValidationErrors('period');

        app(PeriodClose::class)->calculate($admin->company_id, CarbonImmutable::parse('2026-07-01'));
        $august = app(PeriodClose::class)->calculate($admin->company_id, CarbonImmutable::parse('2026-08-01'));
        $this->postJson("/api/v1/accounting/periods/{$august->id}/close")->assertUnprocessable()->assertJsonValidationErrors('period');

        $this->postJson('/api/v1/accounting/periods', ['month' => '2099-01'])->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->postJson('/api/v1/accounting/periods', ['month' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors('month');
    }

    public function test_period_permissions_and_listing(): void
    {
        $admin = $this->signInAdmin();
        Branch::factory()->create(['company_id' => $admin->company_id]);
        $period = app(PeriodClose::class)->calculate($admin->company_id, CarbonImmutable::parse('2026-08-01'));
        $this->assertSame(2, BranchPeriodResult::count());

        $this->getJson('/api/v1/accounting/periods')->assertOk()->assertJsonPath('data.0.month', '2026-08');
        $this->getJson("/api/v1/accounting/periods/{$period->id}")->assertOk()->assertJsonCount(2, 'data.results');

        $adminRole = $this->employeeWithRole($admin, 'admin');
        $this->actingAs($adminRole)->getJson("/api/v1/accounting/periods/{$period->id}")->assertOk();
        $this->actingAs($adminRole)->postJson('/api/v1/accounting/periods', ['month' => '2026-08'])->assertForbidden();
        $this->actingAs($adminRole)->postJson("/api/v1/accounting/periods/{$period->id}/close")->assertForbidden();

        $officer = $this->employeeWithRole($admin, 'loan_officer');
        $this->actingAs($officer)->getJson('/api/v1/accounting/periods')->assertForbidden();

        $finance = $this->employeeWithRole($admin, 'finance');
        $this->actingAs($finance)->postJson("/api/v1/accounting/periods/{$period->id}/close")->assertOk();
    }
}
