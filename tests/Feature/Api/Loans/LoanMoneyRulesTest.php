<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\Account;
use App\Enums\TransactionType;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Models\LoanTransaction;
use App\Models\Penalty;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * Confirmed rules on loan money: 7 (a loan fee that is not deducted never affects the loan), 14 (cash basis for penalties)
 * and 15 (insurance collected goes to INSURANCE RESERVE).
 */
class LoanMoneyRulesTest extends TestCase
{
    use BuildsServiceLoans;
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-10 09:00:00'));
        $this->admin = $this->signInAdmin();
        $this->admin->company->update(['reserve_percent' => 20, 'penalty_type' => 'fixed', 'penalty_value' => 2000]);
    }

    public function test_rule_7_a_non_deducted_fee_changes_nothing_on_the_loan_compared_with_the_same_loan_without_a_fee(): void
    {
        $service = app(LoanService::class);
        $withFee = $this->serviceLoan($this->admin, feeDeducted: false, fee: 5000);
        $noFee = $this->serviceLoan($this->admin, feeDeducted: false, fee: 0);

        $this->assertEquals(5000, $withFee->loan_fee);
        $this->assertSame($this->figures($noFee), $this->figures($withFee), 'rule 7: principal, interest, repayment amount and schedules are identical');
        $this->assertEquals($service->outstanding($noFee), $service->outstanding($withFee), 'rule 7: the fee is never outstanding');
        $this->assertSame($service->deductions($noFee), $service->deductions($withFee), 'rule 7: the approval screen deducts nothing');
        $this->assertSame($this->lines($noFee), $this->lines($withFee), 'rule 7: the disbursement posts no fee line');

        $one = $service->deposit($withFee, 120000, CarbonImmutable::today(), 'CASH', $this->admin);
        $two = $service->deposit($noFee, 120000, CarbonImmutable::today(), 'CASH', $this->admin);
        $this->assertSame($this->allocation($two), $this->allocation($one), 'rule 7: the repayment allocation is identical');
        $this->assertSame($this->lines($noFee), $this->lines($withFee), 'rule 7: the repayment journal is identical');
        $this->assertEquals($service->outstanding($noFee), $service->outstanding($withFee));

        // The only way to settle is the amount disbursed: the loan closes on principal + interest + insurance, never the fee.
        $service->deposit($withFee->fresh(), 11000, CarbonImmutable::today(), 'CASH', $this->admin);
        $this->assertSame('closed', $withFee->fresh()->status->value);

        $this->assertSame(0.0, $this->branchBalance(Account::FeeIncome), 'rule 7 / 14: an unpaid fee is never income');
        $this->assertSame(0.0, $this->branchBalance(Account::LoanFee));
        $this->getJson("/api/v1/loans/{$withFee->id}")->assertOk()
            ->assertJsonPath('data.loan_fee.amount', 5000)
            ->assertJsonPath('data.loan_fee.deducted', false)
            ->assertJsonPath('data.loan_fee.note', 'Not deducted — not part of repayment')
            ->assertJsonMissingPath('data.outstanding.fee');
        $this->assertIntegrityPasses($this->admin);
    }

    public function test_rule_7_a_category_fee_change_after_disbursement_does_not_touch_the_loan_or_its_next_allocation(): void
    {
        $service = app(LoanService::class);
        $loan = $this->serviceLoan($this->admin, feeDeducted: false, fee: 5000);
        $twin = $this->serviceLoan($this->admin, feeDeducted: false, fee: 5000);
        $figures = $this->figures($loan);
        $deductions = $service->deductions($loan);

        LoanCategory::whereKey($loan->loan_category_id)->update(['fee_value' => 9000, 'fee_type' => 'money']);
        $loan->refresh();

        $this->assertEquals(5000, $loan->loan_fee, 'the fee snapshot is never re-priced after disbursement');
        $this->assertSame($figures, $this->figures($loan));
        $this->assertSame($deductions, $service->deductions($loan->fresh()));
        $this->assertSame($this->allocation($service->deposit($twin, 50000, CarbonImmutable::today(), 'CASH', $this->admin)), $this->allocation($service->deposit($loan, 50000, CarbonImmutable::today(), 'CASH', $this->admin)));
        $this->assertSame(0, JournalEntry::whereHas('lines.account', fn ($account) => $account->where('key', Account::FeeIncome->value))->count());
    }

    /**
     * Specification §47: insurance is not part of the architecture. A loan priced now carries none, even when an old
     * category still lists an insurance amount; loans issued before keep theirs (see the legacy fixtures above).
     */
    public function test_a_new_loan_carries_no_insurance_whatever_its_category_says(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id]);
        $category = LoanCategory::factory()->create(['company_id' => $this->admin->company_id, 'insurance' => 1000, 'fee_value' => 0]);
        $loans = app(LoanService::class);

        $loan = $loans->apply($customer, ['loan_category_id' => $category->id, 'amount_applied' => 100000, 'sessions' => 1, 'formula' => 'SIMPLE', 'fee_deduct' => false, 'reason' => 'BIASHARA']);
        $this->assertEquals([0, 130000], [$loan->insurance, $loan->restoration]);

        $loans->approve($loan, 100000);
        $this->assertEquals([0, 130000], [$loan->fresh()->insurance, $loan->fresh()->total_payable]);
    }

    public function test_rule_7_a_deducted_fee_is_still_income_withheld_from_the_cash_paid_out(): void
    {
        $loan = $this->serviceLoan($this->admin, feeDeducted: true, fee: 5000);

        $this->assertSame([
            ['loan_receivable', 100000.0, 0.0],
            ['principal', 0.0, 100000.0],
            ['loan_fee', 5000.0, 0.0],
            ['fee_income', 0.0, 5000.0],
        ], $this->entryLines(JournalEntry::where('description', 'LOAN DISBURSEMENT '.$loan->loan_number)->sole()->id));
        $this->assertSame(['principal' => 100000.0, 'penalty' => 0.0, 'interest' => 30000.0, 'insurance' => 1000.0, 'total' => 131000.0], app(LoanService::class)->outstanding($loan));
        $this->getJson("/api/v1/loans/{$loan->id}")->assertOk()->assertJsonPath('data.loan_fee.note', 'Deducted at disbursement (fee income)');
    }

    public function test_rule_14_the_overdue_job_charges_penalties_without_a_journal_and_the_cash_is_income_when_paid(): void
    {
        $service = app(LoanService::class);
        $loan = $this->serviceLoan($this->admin, feeDeducted: true);

        $this->travelTo(CarbonImmutable::parse('2026-07-20 09:00:00'));
        $entries = JournalEntry::count();
        $this->assertSame(1, $service->applyPenaltiesAndDefaults(CarbonImmutable::today())['penalties']);
        $penalty = Penalty::where('loan_id', $loan->id)->sole();
        $this->assertNull($penalty->accrual_journal_entry_id);
        $this->assertSame($entries, JournalEntry::count(), 'rule 14: no journal until the penalty is paid');
        $this->assertSame(0, JournalEntry::where('transaction_type', TransactionType::PenaltyAccrual->value)->count());
        $this->assertSame(0.0, $this->branchBalance(Account::PenaltyIncome));
        $this->assertSame(0.0, $this->branchBalance(Account::PenaltyReceivable));

        $deposit = $service->deposit($loan->fresh(), 101500, CarbonImmutable::today(), 'CASH', $this->admin);
        $this->assertEquals([100000, 1500], [$deposit->principal, $deposit->penalty]);
        $this->assertSame(1500.0, $this->branchBalance(Account::PenaltyIncome));
        $this->assertSame(1500.0, $this->branchBalance(Account::Penalty));

        $this->postJson("/api/v1/penalties/{$penalty->id}/pay", ['penart_paid' => 500])->assertOk();
        $this->assertSame(2000.0, $this->branchBalance(Account::PenaltyIncome));
        $this->assertSame(0.0, $this->branchBalance(Account::PenaltyReceivable));

        $service->chargePenalty($loan, 700, CarbonImmutable::today());
        $waived = Penalty::where('loan_id', $loan->id)->where('amount', 700)->sole();
        $this->postJson("/api/v1/penalties/{$waived->id}/waive")->assertOk();
        $this->assertNull($waived->fresh()->waiver_journal_entry_id, 'rule 14: waiving a cash-basis penalty posts nothing');
        $this->assertSame(2000.0, $this->branchBalance(Account::PenaltyIncome));
        $this->assertIntegrityPasses($this->admin);
    }

    public function test_rule_15_insurance_collected_is_credited_to_insurance_reserve_and_reversal_restores_it(): void
    {
        $service = app(LoanService::class);
        $loan = $this->serviceLoan($this->admin, feeDeducted: true);

        $deposit = $service->deposit($loan, 131000, CarbonImmutable::today(), 'CASH', $this->admin);

        $lines = $this->entryLines($deposit->journal_entry_id);
        $this->assertContains(['insurance', 1000.0, 0.0], $lines);
        $this->assertContains(['insurance_reserve', 0.0, 1000.0], $lines);
        $this->assertNotContains('insurance_income', array_column($lines, 0), 'rule 15: no insurance income');
        $this->assertSame(1000.0, $this->branchBalance(Account::InsuranceReserve));
        $this->assertSame(0.0, $this->branchBalance(Account::InsuranceIncome));
        $this->assertIntegrityPasses($this->admin);

        $service->reverseRepayment($deposit->fresh(), 'DEVFLOW wrong loan', $this->secondApprover($this->admin));
        $this->assertSame(0.0, $this->branchBalance(Account::InsuranceReserve));
        $this->assertSame(0.0, $this->branchBalance(Account::Insurance));
        $this->assertIntegrityPasses($this->admin);
    }

    /**
     * @return array<string, mixed>
     */
    private function figures(Loan $loan): array
    {
        $loan->refresh();

        return [
            'amount_approved' => (float) $loan->amount_approved,
            'interest_amount' => (float) $loan->interest_amount,
            'total_payable' => (float) $loan->total_payable,
            'restoration' => (float) $loan->restoration,
            'insurance' => (float) $loan->insurance,
            'end_date' => $loan->end_date?->toDateString(),
            'schedules' => $loan->schedules()->orderBy('id')->get()->map(fn ($schedule): array => [(string) $schedule->due_date, (float) $schedule->amount, (float) $schedule->paid_amount])->all(),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function allocation(LoanTransaction $transaction): array
    {
        return collect($transaction->only(['amount', 'principal', 'penalty', 'interest', 'insurance', 'reserve']))->map(fn ($value): float => (float) $value)->all();
    }

    /**
     * Account lines (key, debit, credit) of every journal of a loan and its transactions, in posting order.
     *
     * @return list<array{0: string, 1: float, 2: float}>
     */
    private function lines(Loan $loan): array
    {
        return JournalEntry::query()
            ->where(fn ($query) => $query->where(fn ($inner) => $inner->where('source_type', $loan->getMorphClass())->where('source_id', $loan->id))
                ->orWhere(fn ($inner) => $inner->where('source_type', (new LoanTransaction)->getMorphClass())->whereIn('source_id', $loan->transactions()->select('id'))))
            ->orderBy('id')
            ->pluck('id')
            ->flatMap(fn (int $id): array => $this->entryLines($id))
            ->all();
    }

    private function branchBalance(Account $account): float
    {
        return $this->balances($this->admin, [$account])[$account->value];
    }
}
