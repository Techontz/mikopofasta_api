<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Accounting\AccountingTestHelpers;
use Tests\TestCase;

/**
 * Specification §16 and §21: on the 1st day of the new month the previous month closes by itself and its commission is
 * calculated, so staff see "July commission, awaiting payment" whatever date it is eventually paid on.
 */
class CloseMonthCommandTest extends TestCase
{
    use AccountingTestHelpers, RefreshDatabase;

    public function test_the_previous_month_closes_by_itself_and_its_commission_is_calculated(): void
    {
        $admin = $this->signInAdmin();
        $july = CarbonImmutable::parse('2026-07-01');
        $this->postIncome($admin->company_id, $admin->branch_id, 100000, 10000, 20000, 5000, '2026-07-10');
        $this->postExpense($admin->company_id, $admin->branch_id, 15000, '2026-07-20');

        // The 1st of August: the scheduler runs, with no month given.
        $this->travelTo(CarbonImmutable::parse('2026-08-01 02:00'));
        $this->artisan('mkopa:close-month')->assertSuccessful();

        $period = AccountingPeriod::where('company_id', $admin->company_id)->whereDate('period_start', $july->toDateString())->firstOrFail();
        $this->assertSame(AccountingPeriod::STATUS_CLOSED, $period->status);
        $this->assertNull($period->closed_by, 'the scheduler closes the month, no employee does');
        $this->assertNotNull($period->commission_calculated_at, 'the commission of the closed month is calculated in the same run');
        $this->assertTrue(
            DB::table('journal_entries')->where('company_id', $admin->company_id)->where('description', 'like', 'MONTH END CLOSING 2026-07%')->exists(),
            'the closing entries are posted and dated inside July',
        );
    }

    public function test_running_it_again_changes_nothing_and_still_succeeds(): void
    {
        $admin = $this->signInAdmin();
        $this->postIncome($admin->company_id, $admin->branch_id, 60000, 0, 0, 0, '2026-07-10');
        $this->travelTo(CarbonImmutable::parse('2026-08-01 02:00'));

        $this->artisan('mkopa:close-month')->assertSuccessful();
        $entries = DB::table('journal_entries')->where('company_id', $admin->company_id)->count();

        $this->artisan('mkopa:close-month')->assertSuccessful();

        $this->assertSame($entries, DB::table('journal_entries')->where('company_id', $admin->company_id)->count(), 'a closed month is left alone');
        $this->assertSame(1, AccountingPeriod::where('company_id', $admin->company_id)->count());
    }

    public function test_a_month_can_be_closed_by_hand_with_the_month_option(): void
    {
        $admin = $this->signInAdmin();
        $this->postIncome($admin->company_id, $admin->branch_id, 40000, 0, 0, 0, '2026-06-10');
        $this->travelTo(CarbonImmutable::parse('2026-08-05 09:00'));

        $this->artisan('mkopa:close-month', ['--month' => '2026-06'])->assertSuccessful();

        $this->assertDatabaseHas('accounting_periods', [
            'company_id' => $admin->company_id,
            'period_start' => '2026-06-01',
            'status' => AccountingPeriod::STATUS_CLOSED,
        ]);
    }
}
