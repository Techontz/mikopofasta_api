<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\Duration;
use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanAssessment;
use App\Models\LoanCategory;
use App\Services\Credit\CreditAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Credit assessment engine rules (§36 – §44): scoring direction, capacity, hard overrides, the product clamp, capped
 * contextual signals, zero-weight profitability and an explanation built from the evidence.
 */
class CreditAssessmentRulesTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private CustomerCategory $customerType;

    private LoanCategory $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['credit.context.cache_seconds' => 0]);
        $this->admin = $this->signInAdmin();
        $this->customerType = CustomerCategory::factory()->create(['company_id' => $this->admin->company_id]);
        $this->product = LoanCategory::factory()->forCustomerType($this->customerType)->create(['duration' => Duration::Monthly, 'amount_from' => 20000, 'amount_to' => 2000000]);
        $this->product->branches()->attach($this->admin->branch_id);
    }

    public function test_clean_repayer_gets_the_full_request_with_positive_factors(): void
    {
        $customer = $this->cleanRepayer();
        $assessment = $this->assess($this->application($customer));

        $this->assertSame(300000.0, $assessment['requested_amount']);
        $this->assertSame(300000.0, $assessment['recommended_amount']);
        $this->assertGreaterThanOrEqual(75, $assessment['score']);
        $this->assertSame('low', $assessment['risk_band']);
        $this->assertSame([], $assessment['overrides']);
        $this->assertTrue($assessment['advisory']);

        $factors = collect($assessment['factors'])->keyBy('key');
        $this->assertSame('positive', $factors['repayment_history']['direction']);
        $this->assertSame('positive', $factors['lateness']['direction']);
        $this->assertSame(4, $factors['repayment_history']['evidence']['data']['completed_loans']);
        $this->assertSame(12, $factors['lateness']['evidence']['data']['on_time']);
    }

    public function test_late_payer_is_recommended_less_than_a_clean_repayer(): void
    {
        $clean = $this->assess($this->application($this->cleanRepayer()));

        $late = $this->customer();
        foreach ([800, 600, 400, 200] as $index => $daysAgo) {
            $this->historicLoan($late, $index === 3 ? [40, 45, 35] : [10, 20, 15], startDaysAgo: $daysAgo);
        }
        $assessment = $this->assess($this->application($late));

        $factors = collect($assessment['factors'])->keyBy('key');
        $this->assertLessThan($clean['score'], $assessment['score']);
        $this->assertLessThan($clean['recommended_amount'], $assessment['recommended_amount']);
        $this->assertSame('negative', $factors['lateness']['direction']);
        $this->assertSame(12, $factors['lateness']['evidence']['data']['late']);
        $this->assertSame(1, $factors['default_history']['evidence']['data']['closed_loans_with_default_grade_delay']);
        $this->assertStringContainsString('Against — Payment punctuality', $assessment['explanation']);
    }

    public function test_write_off_history_zeroes_the_recommendation_with_its_reason(): void
    {
        $customer = $this->cleanRepayer();
        $writtenOff = $this->historicLoan($customer, [0, null, null], LoanStatus::WrittenOff, startDaysAgo: 150);
        DB::table('write_offs')->insert(['loan_id' => $writtenOff->id, 'amount' => 60000, 'written_off_on' => CarbonImmutable::today()->subDays(20)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        $assessment = $this->assess($this->application($customer));

        $this->assertSame(0.0, $assessment['recommended_amount']);
        $override = collect($assessment['overrides'])->firstWhere('key', 'write_off');
        $this->assertNotNull($override);
        $this->assertStringContainsString('written off for 60,000', $override['reason']);
        $this->assertStringContainsString('Override (write off)', $assessment['explanation']);
        $this->assertSame('negative', collect($assessment['factors'])->firstWhere('key', 'default_history')['direction']);
    }

    public function test_loan_in_default_caps_the_recommendation(): void
    {
        $customer = $this->cleanRepayer();
        $this->historicLoan($customer, [0, null, null], LoanStatus::Default, startDaysAgo: 150, attributes: ['days_past_due' => 90]);

        $assessment = $this->assess($this->application($customer));

        $this->assertLessThanOrEqual(75000.0, $assessment['recommended_amount']);
        $this->assertSame(0.25, collect($assessment['overrides'])->firstWhere('key', 'open_default')['ratio']);
    }

    public function test_frozen_customer_and_incomplete_kyc_zero_the_recommendation(): void
    {
        $frozen = $this->cleanRepayer();
        Loan::where('customer_id', $frozen->id)->latest('id')->first()->update(['early_settlement' => true, 'frozen_until' => now()->addDays(10)]);
        $assessment = $this->assess($this->application($frozen));
        $this->assertSame(0.0, $assessment['recommended_amount']);
        $this->assertContains('frozen', collect($assessment['overrides'])->pluck('key')->all());

        $unverified = $this->cleanRepayer(['kyc_status' => 'pending']);
        $assessment = $this->assess($this->application($unverified));
        $this->assertSame(0.0, $assessment['recommended_amount']);
        $this->assertSame(['kyc_incomplete'], collect($assessment['overrides'])->pluck('key')->all());
        $this->assertStringContainsString('KYC status: pending', $assessment['overrides'][0]['reason']);
    }

    public function test_low_income_makes_repayment_capacity_the_binding_constraint(): void
    {
        $customer = $this->cleanRepayer(['monthly_income' => 200000]);
        $assessment = $this->assess($this->application($customer));

        // 200,000 income × 40 % = 80,000 a month against a 130,000 monthly instalment: 300,000 × 80/130 = 184,615.
        $this->assertSame(80000.0, $assessment['capacity']['sustainable_monthly_instalment']);
        $this->assertSame(130000.0, $assessment['capacity']['expected_monthly_instalment']);
        $this->assertSame(184615.38, $assessment['steps']['capacity_amount']);
        $this->assertLessThan($assessment['steps']['score_amount'], $assessment['steps']['capacity_amount']);
        $this->assertSame(184000.0, $assessment['recommended_amount']);
        $this->assertStringContainsString('Repayment capacity is the binding constraint', $assessment['explanation']);
    }

    public function test_existing_obligations_reduce_capacity(): void
    {
        $customer = $this->cleanRepayer(['monthly_income' => 400000]);
        $advanceCategoryId = DB::table('salary_advance_categories')->insertGetId(['company_id' => $customer->company_id, 'name' => 'PERIFELAR', 'amount_from' => 10000, 'amount_to' => 500000, 'interest_rate' => 10, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('salary_advances')->insert(['company_id' => $customer->company_id, 'branch_id' => $customer->branch_id, 'customer_id' => $customer->id, 'salary_advance_category_id' => $advanceCategoryId, 'amount' => 100000, 'interest_rate' => 10, 'total_payable' => 110000, 'fee' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $advanceId = (int) DB::table('salary_advances')->where('customer_id', $customer->id)->value('id');
        DB::table('salary_advance_payments')->insert([
            ['salary_advance_id' => $advanceId, 'amount' => 5000, 'paid_on' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
            ['salary_advance_id' => $advanceId, 'amount' => 5000, 'paid_on' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $assessment = $this->assess($this->application($customer));
        $obligations = collect($assessment['factors'])->firstWhere('key', 'existing_obligations')['evidence'];

        // 110,000 payable less two payments of 5,000 each — not double-counted per payment row.
        $this->assertSame(100000.0, $obligations['data']['salary_advance_outstanding']);
        $this->assertSame(100000.0, $assessment['capacity']['existing_monthly_obligation']);
        $this->assertSame(120000.0, $assessment['capacity']['sustainable_monthly_instalment']);
        $this->assertNull($obligations['data']['external_lender_obligations']);
        $this->assertStringContainsString('no external-lender register', $obligations['notes'][0]);
    }

    public function test_product_limits_clamp_after_scoring_but_never_score(): void
    {
        $loan = $this->application($this->cleanRepayer());
        $before = $this->assess($loan);

        $this->assertSame(['loan_product'], $before['excluded_factors']);
        $this->assertNotContains('loan_product', collect($before['factors'])->pluck('key')->all());
        $this->assertFalse($before['limits']['scored']);
        $this->assertFalse($before['limits']['clamped']);

        // A different product with other limits, name and pricing: the score and every factor stay the same.
        $otherProduct = LoanCategory::factory()->forCustomerType($this->customerType)->create(['name' => 'MSHAHARA', 'interest_rate' => 10, 'amount_from' => 20000, 'amount_to' => 200000]);
        $loan->update(['loan_category_id' => $otherProduct->id]);
        $after = $this->assess($loan->fresh());

        $this->assertSame($before['score'], $after['score']);
        $this->assertSame($before['factors'], $after['factors']);
        $this->assertSame(300000.0, $after['steps']['rounded']);
        $this->assertSame(200000.0, $after['recommended_amount']);
        $this->assertTrue($after['limits']['clamped']);
        $this->assertStringContainsString('The product itself was not scored', $after['explanation']);

        // Below the product minimum the product cannot be disbursed at the supportable amount.
        $otherProduct->update(['amount_from' => 250000, 'amount_to' => 2000000]);
        Customer::whereKey($loan->customer_id)->update(['monthly_income' => 200000]);
        $belowMinimum = $this->assess($loan->fresh());
        $this->assertSame(184000.0, $belowMinimum['steps']['rounded']);
        $this->assertSame(0.0, $belowMinimum['recommended_amount']);
        $this->assertStringContainsString('below the product minimum', $belowMinimum['limits']['clamp_reason']);
    }

    public function test_customer_type_branch_and_officer_influence_stays_inside_its_caps(): void
    {
        config(['credit.context.minimum_sample_loans' => 3]);
        for ($peer = 0; $peer < 4; $peer++) {
            $this->historicLoan($this->customer(), [0, 0, 0], startDaysAgo: 300, attributes: ['employee_id' => $this->admin->id]);
        }

        $weak = $this->customer();
        $this->historicLoan($weak, [60, null, null], LoanStatus::Overdue, startDaysAgo: 200, attributes: ['employee_id' => $this->admin->id]);
        $loan = $this->application($weak, attributes: ['employee_id' => $this->admin->id]);

        $assessment = $this->assess($loan);
        $factors = collect($assessment['factors'])->keyBy('key');

        foreach (['customer_type_performance', 'branch_performance', 'officer_performance'] as $key) {
            $this->assertTrue($factors[$key]['evidence']['data']['matched'], "{$key} should be read from real portfolio data");
            $this->assertSame('contextual', $factors[$key]['group']);
            $this->assertLessThanOrEqual(config("credit.caps.{$key}") * 100, $factors[$key]['contribution']);
        }
        $this->assertSame(5, $factors['customer_type_performance']['evidence']['data']['loans']);
        $this->assertLessThanOrEqual(10.0, $assessment['contextual_influence']['contribution']);
        $this->assertTrue($assessment['contextual_influence']['within_cap']);
        $individual = collect($assessment['factors'])->where('group', 'individual')->sum('contribution');

        // Even a configuration that tries to let group signals dominate is held to the engine's hard ceiling.
        config([
            'credit.weights.customer_type_performance' => 0.6, 'credit.weights.branch_performance' => 0.6, 'credit.weights.officer_performance' => 0.6,
            'credit.caps.customer_type_performance' => 0.6, 'credit.caps.branch_performance' => 0.6, 'credit.caps.officer_performance' => 0.6,
            'credit.caps.contextual_total' => 0.9,
        ]);
        $misconfigured = $this->assess($loan->fresh());

        $this->assertLessThanOrEqual(CreditAssessment::MAX_CONTEXTUAL_SHARE * 100 + 0.01, $misconfigured['contextual_influence']['contribution']);
        $this->assertSame(20.0, $misconfigured['contextual_influence']['cap']);
        $this->assertEqualsWithDelta($individual, collect($misconfigured['factors'])->where('group', 'individual')->sum('contribution'), 0.001);
    }

    public function test_profitability_carries_no_weight_and_never_raises_the_recommendation(): void
    {
        config(['credit.recommendation.full_score' => 99.0]);
        $modest = $this->assess($this->application($this->cleanRepayer(loanAttributes: ['loan_fee' => 0])));
        $profitable = $this->assess($this->application($this->cleanRepayer(loanAttributes: ['loan_fee' => 500000])));

        $profitability = collect($profitable['factors'])->firstWhere('key', 'profitability');
        $this->assertSame(2000000.0, $profitability['evidence']['data']['loan_fees']);
        $this->assertSame(0.0, collect($modest['factors'])->firstWhere('key', 'profitability')['evidence']['data']['loan_fees']);
        $this->assertSame(0.0, $profitability['weight']);
        $this->assertSame(0.0, $profitability['contribution']);
        $this->assertSame('neutral', $profitability['direction']);
        $this->assertSame('supporting', $profitability['group']);
        $this->assertSame($modest['score'], $profitable['score']);
        $this->assertSame($modest['recommended_amount'], $profitable['recommended_amount']);
        $this->assertLessThan(300000.0, $profitable['recommended_amount']);

        config(['credit.weights.profitability' => 0.5, 'credit.caps.profitability' => 0.5]);
        $forced = $this->assess(Loan::latest('id')->first());
        $this->assertSame($profitable['score'], $forced['score']);
        $this->assertSame(0.0, collect($forced['factors'])->firstWhere('key', 'profitability')['contribution']);
    }

    public function test_explanation_and_factors_name_their_evidence(): void
    {
        $assessment = $this->assess($this->application($this->cleanRepayer(['monthly_income' => 200000])));

        $this->assertStringContainsString('The requested amount is 300,000', $assessment['explanation']);
        $this->assertStringContainsString('the system recommends 184,000', $assessment['explanation']);
        $this->assertStringContainsString('carried no weight', $assessment['explanation']);

        foreach ($assessment['factors'] as $factor) {
            $this->assertNotEmpty($factor['evidence']['sources'], "{$factor['key']} must name its sources");
            $this->assertNotEmpty($factor['evidence']['data'], "{$factor['key']} must carry its data");
            $this->assertNotSame('', $factor['summary']);
            $this->assertArrayHasKey('weight', $factor);
            $this->assertArrayHasKey('value', $factor);
            $this->assertArrayHasKey('contribution', $factor);
            $this->assertContains($factor['direction'], ['positive', 'negative', 'neutral']);
        }

        $capacity = collect($assessment['factors'])->firstWhere('key', 'repayment_capacity');
        $this->assertSame(200000.0, $capacity['evidence']['data']['monthly_income']);
        $this->assertSame('monthly_income', $capacity['evidence']['data']['income_field']);
        $this->assertStringContainsString('Monthly income 200,000 (monthly_income)', $capacity['summary']);
        $this->assertStringContainsString('12 of 12 instalments due were paid on time', collect($assessment['factors'])->firstWhere('key', 'lateness')['summary']);
        $this->assertSame(4, $assessment['summary']['previous_loans']);
        $this->assertSame(['repayment_history', 'lateness', 'repayment_capacity', 'default_history', 'existing_obligations', 'relationship_depth', 'customer_type_performance', 'branch_performance', 'officer_performance', 'profitability'], collect($assessment['factors'])->pluck('key')->all());
    }

    public function test_contract_ending_before_maturity_reduces_capacity(): void
    {
        $stable = $this->assess($this->application($this->cleanRepayer(['monthly_income' => 300000])));
        $ending = $this->assess($this->application($this->cleanRepayer(['monthly_income' => 300000, 'contract_expiry_date' => now()->addDays(20)->toDateString()])));

        $capacity = collect($ending['factors'])->firstWhere('key', 'repayment_capacity');
        $this->assertArrayHasKey('contract_expiry_date', $capacity['evidence']['data']['income_ends_before_maturity']);
        $this->assertStringContainsString('Income stability', $capacity['summary']);
        $this->assertLessThan(collect($stable['factors'])->firstWhere('key', 'repayment_capacity')['contribution'], $capacity['contribution']);
    }

    public function test_recording_is_advisory_and_changes_nothing_on_the_loan(): void
    {
        $loan = $this->application($this->cleanRepayer(['monthly_income' => 200000]));
        $before = $loan->fresh()->getAttributes();

        $record = app(CreditAssessment::class)->record($loan, $this->admin);

        $this->assertSame($before, $loan->fresh()->getAttributes());
        $this->assertSame(1, LoanAssessment::count());
        $this->assertEquals(184000, $record->recommended_amount);
        $this->assertSame($this->admin->id, $record->assessed_by);
        $this->assertSame((string) config('credit.engine_version'), $record->engine_version);

        $presented = app(CreditAssessment::class)->present($record->fresh());
        $this->assertTrue($presented['stored']);
        $this->assertEquals(184000, $presented['summary']['recommended_amount']);
        $this->assertEquals(80000, $presented['capacity']['sustainable_monthly_instalment']);
        $this->assertCount(10, $presented['factors']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create($attributes + [
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'customer_category_id' => $this->customerType->id,
            'monthly_income' => 1000000,
            'take_home' => null,
            'basic_salary' => null,
            'dependents' => 0,
            'contract_expiry_date' => null,
            'retirement_date' => null,
            'created_at' => now()->subMonths(30),
        ]);
    }

    /**
     * A customer with four closed loans, every instalment paid on its due date.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $loanAttributes
     */
    private function cleanRepayer(array $attributes = [], array $loanAttributes = []): Customer
    {
        $customer = $this->customer($attributes);
        foreach ([800, 600, 400, 200] as $daysAgo) {
            $this->historicLoan($customer, [0, 0, 0], startDaysAgo: $daysAgo, attributes: $loanAttributes);
        }

        return $customer;
    }

    /**
     * A disbursed monthly loan of 90,000 (+30 % interest) with one instalment per delay: an integer is the number of days
     * the instalment was paid after its due date, null leaves it unpaid.
     *
     * @param  list<int|null>  $delays
     * @param  array<string, mixed>  $attributes
     */
    private function historicLoan(Customer $customer, array $delays, LoanStatus $status = LoanStatus::Closed, int $startDaysAgo = 400, array $attributes = []): Loan
    {
        $amount = 90000.0;
        $sessions = count($delays);
        $instalment = round($amount * 1.3 / $sessions, 2);
        $start = CarbonImmutable::today()->subDays($startDaysAgo);

        $loan = Loan::factory()->create($attributes + [
            'customer_id' => $customer->id,
            'loan_category_id' => $this->product->id,
            'status' => $status,
            'amount_applied' => $amount,
            'amount_approved' => $amount,
            'interest_amount' => $amount * 0.3,
            'total_payable' => $amount * 1.3,
            'restoration' => $instalment,
            'sessions' => $sessions,
            'duration' => Duration::Monthly,
            'withdrawn_at' => $start->toDateString(),
        ]);

        foreach ($delays as $index => $delay) {
            $due = $start->addDays(30 * ($index + 1));
            DB::table('loan_schedules')->insert(['loan_id' => $loan->id, 'due_date' => $due->toDateString(), 'amount' => $instalment, 'paid_amount' => $delay === null ? 0 : $instalment, 'created_at' => now(), 'updated_at' => now()]);

            if ($delay !== null) {
                DB::table('loan_transactions')->insert([
                    'company_id' => $loan->company_id, 'branch_id' => $loan->branch_id, 'customer_id' => $customer->id, 'loan_id' => $loan->id,
                    'type' => 'deposit', 'description' => 'DEPOSIT', 'amount' => $instalment, 'principal' => round($amount / $sessions, 2),
                    'interest' => round($amount * 0.3 / $sessions, 2), 'transaction_date' => $due->addDays($delay)->toDateString(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        return $loan;
    }

    /**
     * A pending application of 300,000 over 3 monthly instalments of 130,000.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function application(Customer $customer, float $amount = 300000, array $attributes = []): Loan
    {
        return Loan::factory()->create($attributes + [
            'customer_id' => $customer->id,
            'loan_category_id' => $this->product->id,
            'amount_applied' => $amount,
            'duration' => Duration::Monthly,
            'sessions' => 3,
            'interest_amount' => $amount * 0.3,
            'total_payable' => $amount * 1.3,
            'restoration' => round($amount * 1.3 / 3, 2),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function assess(Loan $loan): array
    {
        return app(CreditAssessment::class)->for($loan);
    }
}
