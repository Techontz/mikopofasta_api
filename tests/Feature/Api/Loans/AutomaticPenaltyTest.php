<?php

namespace Tests\Feature\Api\Loans;

use App\Models\Loan;
use App\Models\Penalty;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Payments\InteractsWithRepayments;
use Tests\TestCase;

/**
 * The overdue job charges each missed instalment once, on its arrears (instalment − confirmed paid) × the Penalty Setting
 * percentage, through the existing Penalty records; the Penalty List and Loan Outstanding include it.
 */
class AutomaticPenaltyTest extends TestCase
{
    use InteractsWithRepayments, RefreshDatabase;

    public function test_the_penalty_is_charged_on_the_arrears_whether_partly_paid_or_not_and_only_once(): void
    {
        $admin = $this->signInAdmin();
        $admin->company->forceFill(['penalty_type' => 'percentage', 'penalty_value' => 10])->save();
        $loan = $this->loanWithInstalments($admin);
        $loans = app(LoanService::class);

        $loans->deposit($loan, 100000 + 50000, today()->subDay()->toImmutable());

        $loans->applyPenaltiesAndDefaults(CarbonImmutable::today());
        $loans->applyPenaltiesAndDefaults(CarbonImmutable::today());

        $penalties = Penalty::where('loan_id', $loan->id)->orderBy('penalty_date')->get();
        $this->assertSame([5000.0, 10000.0], $penalties->map(fn (Penalty $penalty): float => (float) $penalty->amount)->all(), 'Paid in full: none; half paid: 10% of 50,000; unpaid: 10% of 100,000.');
        $this->assertNotNull($penalties[0]->loan_schedule_id);

        $this->assertEquals(15000, $loans->outstanding($loan->fresh())['penalty']);
        $this->assertEqualsCanonicalizing([5000, 10000], array_map('intval', array_column($this->getJson('/api/v1/penalties')->assertOk()->json('data'), 'remaining')));
    }

    public function test_a_later_run_uses_the_current_setting_and_a_paid_penalty_leaves_the_list(): void
    {
        $admin = $this->signInAdmin();
        $admin->company->forceFill(['penalty_type' => 'percentage', 'penalty_value' => 10])->save();
        $loan = $this->loanWithInstalments($admin);
        $loans = app(LoanService::class);

        $loans->applyPenaltiesAndDefaults(today()->subDays(4)->toImmutable());
        $admin->company->forceFill(['penalty_value' => 20])->save();
        $loans->applyPenaltiesAndDefaults(CarbonImmutable::today());

        $this->assertSame([10000.0, 10000.0, 20000.0], Penalty::where('loan_id', $loan->id)->orderBy('penalty_date')->pluck('amount')->map(fn ($amount): float => (float) $amount)->all());

        $penalty = Penalty::where('loan_id', $loan->id)->orderBy('penalty_date')->firstOrFail();
        $this->postJson("/api/v1/penalties/{$penalty->id}/pay", ['penart_paid' => 10000])->assertOk();
        $this->assertCount(2, $this->getJson('/api/v1/penalties')->json('data'));
        $this->assertEquals(30000, $loans->outstanding($loan->fresh())['penalty']);
    }

    /**
     * A 300,000 loan in three 100,000 instalments, due 10, 5 and 2 days ago.
     */
    private function loanWithInstalments($admin): Loan
    {
        $loan = $this->activeLoan($admin);
        $loan->forceFill(['amount_approved' => 230770, 'interest_amount' => 69230, 'total_payable' => 300000, 'restoration' => 100000, 'sessions' => 3])->save();
        $loan->schedules()->delete();
        $loan->schedules()->create(['due_date' => today()->subDays(10), 'amount' => 100000]);
        $loan->schedules()->create(['due_date' => today()->subDays(5), 'amount' => 100000]);
        $loan->schedules()->create(['due_date' => today()->subDays(2), 'amount' => 100000]);

        return $loan->fresh();
    }
}
