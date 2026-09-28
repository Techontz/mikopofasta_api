<?php

namespace Tests\Feature\Api\SalaryAdvance;

use App\Http\Resources\Api\V1\SalaryAdvance\SalaryAdvanceResource;
use App\Models\SalaryAdvance;
use App\Services\DashboardStatistics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The salary advance "old" / "new" alert uses the single repayment-cycle rule of the dashboard: the cycle ends on the
 * 5th of the month after approval (creation when not approved).
 */
class SalaryAdvanceAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_turns_old_once_the_repayment_cycle_has_ended(): void
    {
        $advance = new SalaryAdvance(['approved_at' => '2026-08-20 10:00:00', 'total_payable' => 1000]);
        $advance->created_at = CarbonImmutable::parse('2026-08-19 09:00:00');

        foreach (['2026-09-05 09:59:59' => 'new', '2026-09-05 10:00:01' => 'old', '2026-10-01 00:00:00' => 'old', '2026-08-25 12:00:00' => 'new'] as $now => $expected) {
            $this->travelTo(CarbonImmutable::parse($now));

            $this->assertSame($expected, (new SalaryAdvanceResource($advance))->toArray(Request::create('/'))['alert'], "at {$now}");
            $this->assertSame($expected === 'old', DashboardStatistics::repaymentCycleEnded($advance), "dashboard at {$now}");
        }

        $pending = new SalaryAdvance(['total_payable' => 1000]);
        $pending->created_at = CarbonImmutable::parse('2026-07-01 08:00:00');
        $this->assertSame('old', (new SalaryAdvanceResource($pending))->toArray(Request::create('/'))['alert']);
    }
}
