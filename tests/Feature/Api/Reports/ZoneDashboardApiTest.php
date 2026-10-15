<?php

namespace Tests\Feature\Api\Reports;

use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\LoanCategory;
use App\Models\Zone;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Accounting\AccountingTestHelpers;
use Tests\Feature\Api\Loans\BuildsServiceLoans;
use Tests\TestCase;

/**
 * Zone Manager dashboard (GET /dashboard/zone): only the zone's branches; Active + Arrears + Default add up to Total
 * Outstanding; today's collections and disbursements; branch performance; pending approvals and this month's pipeline.
 */
class ZoneDashboardApiTest extends TestCase
{
    use AccountingTestHelpers, BuildsServiceLoans, RefreshDatabase;

    public function test_zone_manager_sees_only_the_branches_of_the_zone(): void
    {
        $admin = $this->signInAdmin();
        $today = CarbonImmutable::today();
        $zone = Zone::create(['company_id' => $admin->company_id, 'name' => 'LAKE']);
        $admin->branch->update(['zone_id' => $zone->id, 'name' => 'ILALA']);
        $second = Branch::factory()->create(['company_id' => $admin->company_id, 'zone_id' => $zone->id, 'name' => 'TEMEKE']);
        $outside = Branch::factory()->create(['company_id' => $admin->company_id, 'name' => 'ARUSHA']);
        $manager = $this->employeeWithRole($admin, 'zone_manager');
        $manager->update(['zone_id' => $zone->id]);

        $active = $this->serviceLoan($admin, amount: 100000, legacyInsurance: 0);
        $inArrears = $this->serviceLoan($admin, amount: 100000, branchId: $second->id, legacyInsurance: 0);
        $inArrears->schedules()->update(['due_date' => $today->subDays(3)->toDateString()]);
        $inDefault = $this->serviceLoan($admin, amount: 100000, legacyInsurance: 0);
        $inDefault->forceFill(['status' => LoanStatus::Default])->save();
        $this->serviceLoan($admin, amount: 100000, branchId: $outside->id, legacyInsurance: 0);
        app(LoanService::class)->deposit($active->fresh(), 30000, $today, 'CASH', $admin);
        $this->application($admin, $second->id);

        $data = $this->actingAs($manager)->getJson('/api/v1/dashboard/zone')->assertOk()->json('data');

        $this->assertSame('LAKE', $data['zone']);
        $cards = $data['cards'];
        $this->assertSame([2, 0, 1, 3], [$cards['active']['count'], $cards['arrears']['count'], $cards['default']['count'], $cards['outstanding']['count']], 'Status cards as on the Branch dashboard; the overdue loan is still ACTIVE until the overdue run.');
        $this->assertSame(3, $data['today']['disbursement']['loans'], 'The loan outside the zone is not counted.');
        $this->assertEquals(30000.0, $data['today']['collection']['amount']);

        $this->assertSame(['ILALA', 'TEMEKE'], collect($data['branches']['rows'])->pluck('label')->sort()->values()->all());
        $temeke = collect($data['branches']['rows'])->firstWhere('label', 'TEMEKE');
        $this->assertEquals(100.0, $temeke['arrears_percent']);
        $this->assertSame(1, $temeke['active_loans']);
        $ilala = collect($data['branches']['rows'])->firstWhere('label', 'ILALA');
        $this->assertEquals(50.0, $ilala['default_percent'], 'One of the two disbursed loans of ILALA is in default.');
        $this->assertEquals(30000.0, $ilala['collected']);
        $this->assertSame(3, $data['branches']['total']['active_loans']);

        $this->assertSame(1, collect($data['pipeline'])->firstWhere('key', 'manager')['count']);

        $one = $this->getJson("/api/v1/dashboard/zone?branch_id={$second->id}")->assertOk()->json('data');
        $this->assertSame(['TEMEKE'], collect($one['branches']['rows'])->pluck('label')->all());
        $this->assertSame(1, $one['cards']['outstanding']['count']);

        $this->getJson("/api/v1/dashboard/zone?branch_id={$outside->id}")->assertForbidden();
    }

    public function test_staff_who_cannot_view_loans_are_refused(): void
    {
        $admin = $this->signInAdmin();
        $hr = $this->employeeWithRole($admin, 'hr');

        $this->actingAs($hr)->getJson('/api/v1/dashboard/zone')->assertForbidden();
    }

    private function application(Employee $admin, int $branchId): void
    {
        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $branchId]);
        $category = LoanCategory::factory()->create(['company_id' => $admin->company_id]);
        app(LoanService::class)->apply($customer, ['loan_category_id' => $category->id, 'amount_applied' => 50000, 'sessions' => 1, 'formula' => 'SIMPLE', 'fee_deduct' => true, 'reason' => 'BIASHARA']);
    }
}
