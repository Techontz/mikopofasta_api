<?php

namespace Tests\Feature\Api\Loans;

use App\Models\Branch;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Payments\InteractsWithRepayments;
use Tests\TestCase;

/**
 * Loan Repayment History: the existing schedule instalment by instalment, with the confirmed amount paid on it, its
 * collection date, what is still outstanding and Paid / Partial Paid / Unpaid.
 */
class LoanRepaymentHistoryTest extends TestCase
{
    use InteractsWithRepayments, RefreshDatabase;

    public function test_each_instalment_shows_its_payments_collection_date_and_status(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);
        $loan->schedules()->delete();
        $first = today()->subDays(20);
        $loan->schedules()->create(['due_date' => $first, 'amount' => 50000]);
        $loan->schedules()->create(['due_date' => today()->subDays(5), 'amount' => 50000]);
        $loan->schedules()->create(['due_date' => today()->addDays(10), 'amount' => 30000]);

        $loans = app(LoanService::class);
        $loans->deposit($loan, 30000, CarbonImmutable::parse($first)->subDay());
        $loans->deposit($loan, 20000, CarbonImmutable::parse($first)->addDays(2));
        $loans->deposit($loan, 20000, today()->subDays(3)->toImmutable());

        $data = $this->getJson("/api/v1/loans/{$loan->id}/repayment-history")->assertOk()->json('data');

        $this->assertSame($loan->loan_number, $data['loan']['loan_number']);
        $this->assertSame([
            [$first->toDateString(), $first->copy()->addDays(2)->toDateString(), 50000, 50000, 0, 'paid'],
            [today()->subDays(5)->toDateString(), today()->subDays(3)->toDateString(), 50000, 20000, 30000, 'partial'],
            [today()->addDays(10)->toDateString(), null, 30000, 0, 30000, 'unpaid'],
        ], array_map(fn (array $row): array => [$row['deduction_date'], $row['collection_date'], (int) $row['instalment_amount'], (int) $row['paid_amount'], (int) $row['outstanding_amount'], $row['status']], $data['rows']));
        $this->assertEquals(['instalments' => 130000, 'paid' => 70000, 'outstanding' => 60000], $data['totals']);
    }

    public function test_only_staff_who_can_see_the_loan_get_its_history(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);
        $otherBranch = Branch::factory()->create(['company_id' => $admin->company_id]);

        $this->actingAs($this->employeeWithRole($admin, 'loan_officer', $otherBranch->id))->getJson("/api/v1/loans/{$loan->id}/repayment-history")->assertNotFound();
        $this->actingAs($this->employeeWithRole($admin, 'loan_officer'))->getJson("/api/v1/loans/{$loan->id}/repayment-history")->assertOk();
    }
}
