<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Enums\PaymentStatus;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DividendDeclaration;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\PenaltyPayment;
use App\Services\AccessControl;
use App\Services\Ledger;
use App\Services\LoanService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\UsesSecondApprover;
use Tests\Feature\Api\Payments\InteractsWithRepayments;
use Tests\TestCase;

/**
 * Loan repayment reversal (spec §22, §26): dependency-checked, reverses every allocated component, restores the loan's
 * operational state and returns the money to suspense.
 */
class LoanRepaymentReversalTest extends TestCase
{
    use InteractsWithRepayments;
    use RefreshDatabase;
    use UsesSecondApprover;

    /**
     * Fund, income and holding accounts a repayment touches.
     *
     * @var list<Account>
     */
    private const BRANCH_ACCOUNTS = [
        Account::Principal, Account::LoanReceivable, Account::Penalty, Account::PenaltyIncome, Account::Interest,
        Account::Reserve, Account::InterestIncome, Account::Insurance, Account::InsuranceIncome,
    ];

    public function test_full_reversal_restores_ledger_outstanding_schedules_penalties_and_returns_money_to_suspense(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin, penalty: 10000);
        $loan->update(['insurance' => 5000]);
        $payment = $this->unmatchedPayment($admin, 142000);
        $before = $this->snapshot($admin) + ['suspense' => $this->balance($admin, Account::Suspense), 'bank' => $this->balance($admin, Account::Bank)];

        app(PaymentService::class)->allocateSuspense($payment, $loan, 142000, $admin);
        $deposit = $loan->transactions()->where('type', 'deposit')->sole();
        $this->assertEquals([100000, 10000, 30000, 2000, 6000], [$deposit->principal, $deposit->penalty, $deposit->interest, $deposit->insurance, $deposit->reserve]);
        $this->assertSame(PaymentStatus::Allocated, $payment->fresh()->status);
        $this->assertNotNull($deposit->journal_entry_id);
        $this->assertSame(1, PenaltyPayment::where('loan_transaction_id', $deposit->id)->count());

        $this->actingAs($approver = $this->secondApprover($admin));
        $this->getJson("/api/v1/loans/{$loan->id}")->assertOk()
            ->assertJsonPath('data.transactions.0.can_reverse', true)
            ->assertJsonPath('data.transactions.0.reverse_blocked_reason', null);

        $requested = $this->postJson("/api/v1/loans/{$loan->id}/transactions/{$deposit->id}/reverse", ['reason' => 'DEVFLOW wrong loan'])
            ->assertJsonPath('data.status', 'active');
        $this->assertNull($deposit->fresh()->reversed_at, 'a request posts nothing');
        $this->approveReversal($requested, $checker = $this->secondApprover($admin))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);

        $this->assertSame($before, $this->snapshot($admin) + ['suspense' => $this->balance($admin, Account::Suspense), 'bank' => $this->balance($admin, Account::Bank)]);
        $this->assertEquals(['principal' => 100000, 'penalty' => 10000, 'interest' => 30000, 'insurance' => 5000, 'total' => 145000], app(LoanService::class)->outstanding($loan->fresh()));
        $this->assertEquals(0, $loan->schedules()->sum('paid_amount'));
        $this->assertEquals(0, Penalty::where('loan_id', $loan->id)->sole()->paid_amount);
        $this->assertSame(0, PenaltyPayment::count());
        $this->assertSame(0.0, $loan->fresh()->paid_amount);

        $deposit->refresh();
        $this->assertNotNull($deposit->reversed_at);
        $this->assertSame('DEVFLOW wrong loan', $deposit->reversal_reason);
        $this->assertSame($checker->id, $deposit->reversed_by);
        $this->assertNotSame($approver->id, $checker->id);
        $this->assertSame($deposit->journal_entry_id, JournalEntry::find($deposit->reversal_journal_entry_id)->reversal_of_id);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Unallocated, $payment->status);
        $this->assertSame(142000.0, $payment->unallocated_amount);
        $this->assertNotNull($payment->allocations()->sole()->reversed_at);
        $this->assertTrue(AuditLog::where('action', 'LoanTransaction.reversed')->where('auditable_id', $deposit->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'REPAYMENT_REVERSED')->where('auditable_id', $loan->id)->exists());

        $this->getJson("/api/v1/loans/{$loan->id}")->assertOk()
            ->assertJsonPath('data.transactions.0.reversed', true)
            ->assertJsonPath('data.transactions.0.can_reverse', false)
            ->assertJsonPath('data.transactions.0.reverse_blocked_reason', 'This repayment has already been reversed.');
        $this->postJson("/api/v1/loans/{$loan->id}/transactions/{$deposit->id}/reverse", ['reason' => 'again'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'This repayment has already been reversed.']);

        app(PaymentService::class)->allocateSuspense($payment->fresh(), $loan->fresh(), 50000, $admin);
        $this->assertEquals(50000, app(LoanService::class)->outstanding($loan->fresh())['principal']);
    }

    public function test_loan_closed_by_the_repayment_reopens_and_its_freeze_decision_is_cleared(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);
        $loan->category->update(['freeze_time_days' => 60]);
        $loan->update(['disbursed_at' => now()->subDays(10)]);

        $deposit = app(LoanService::class)->deposit($loan, 130000, CarbonImmutable::today(), 'CASH', $admin);
        $loan->refresh();
        $this->assertSame(LoanStatus::Closed, $loan->status);
        $this->assertTrue($loan->early_settlement);
        $this->assertNotNull($loan->frozen_until);
        $this->assertSame('close', $loan->customer->fresh()->status);

        $this->approveReversal($this->actingAs($this->secondApprover($admin))->postJson("/api/v1/loans/{$loan->id}/transactions/{$deposit->id}/reverse", ['reason' => 'Cheque bounced']))->assertOk();

        $loan->refresh();
        $this->assertSame(LoanStatus::Active, $loan->status);
        $this->assertNull($loan->closed_at);
        $this->assertNull($loan->early_settlement);
        $this->assertNull($loan->frozen_until);
        $this->assertNull($loan->freeze_started_at);
        $this->assertSame('open', $loan->customer->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'SETTLEMENT_FREEZE_REVERSED')->where('auditable_id', $loan->id)->exists());
        $this->assertSame(130000.0, app(LoanService::class)->outstanding($loan)['total']);

        $suspense = Payment::sole();
        $this->assertSame(PaymentStatus::Unallocated, $suspense->status);
        $this->assertSame(130000.0, $suspense->unallocated_amount);
        $this->assertSame(130000.0, $this->balance($admin, Account::Suspense, null), 'the central HQ pending account (§11)');
        $this->assertSame(0.0, $this->balance($admin, Account::InterestIncome, $admin->branch_id));
    }

    public function test_later_repayment_blocks_and_reversals_go_newest_first(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);
        $loans = app(LoanService::class);
        $first = $loans->deposit($loan, 20000, CarbonImmutable::today(), 'CASH', $admin);
        $second = $loans->deposit($loan, 30000, CarbonImmutable::today(), 'CASH', $admin);
        $this->actingAs($this->secondApprover($admin));

        $this->postJson("/api/v1/loans/{$loan->id}/transactions/{$first->id}/reverse", ['reason' => 'Wrong amount'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'A later repayment of TZS 30,000 on '.today()->toDateString().' exists; reverse repayments newest first.']);

        $this->approveReversal($this->postJson("/api/v1/loans/{$loan->id}/transactions/{$second->id}/reverse", ['reason' => 'Wrong amount']))->assertOk();
        $this->approveReversal($this->postJson("/api/v1/loans/{$loan->id}/transactions/{$first->id}/reverse", ['reason' => 'Wrong amount']))->assertOk();
        $this->assertSame(130000.0, $loans->outstanding($loan->fresh())['total']);
        $this->assertSame(2, Payment::where('status', PaymentStatus::Unallocated->value)->count());
    }

    public function test_top_up_saving_clear_and_written_off_repayments_are_blocked(): void
    {
        $admin = $this->signInAdmin();
        $loans = app(LoanService::class);

        $topup = $this->activeLoan($admin);
        $topupDeposit = $loans->deposit($topup, 10000, CarbonImmutable::today(), 'TOPUP', $admin);
        $this->postJson("/api/v1/loans/{$topup->id}/transactions/{$topupDeposit->id}/reverse", ['reason' => 'Undo'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'This repayment settled the loan from a top-up disbursement, but the top-up loan could not be found; it needs a manual correction.']);

        $saving = $this->activeLoan($admin);
        $savingDeposit = $loans->deposit($saving, 10000, CarbonImmutable::today(), 'SAVING', $admin);
        $this->postJson("/api/v1/loans/{$saving->id}/transactions/{$savingDeposit->id}/reverse", ['reason' => 'Undo'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $writtenOff = $this->activeLoan($admin);
        $writtenOffDeposit = $loans->deposit($writtenOff, 10000, CarbonImmutable::today(), 'CASH', $admin);
        $writtenOff->update(['status' => LoanStatus::Default]);
        $requestId = $this->postJson("/api/v1/loans/{$writtenOff->id}/write-off")->assertCreated()->json('write_off_request.id');
        $this->approveAsSecondUser($admin, "/api/v1/loans/write-off-requests/{$requestId}/approve");
        $this->postJson("/api/v1/loans/{$writtenOff->id}/transactions/{$writtenOffDeposit->id}/reverse", ['reason' => 'Undo'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'The loan has been written off; its repayments cannot be reversed.']);

        $this->assertSame(0, LoanTransaction::whereNotNull('reversed_at')->count());
    }

    public function test_settled_loan_of_a_customer_who_borrowed_again_is_blocked(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);
        $deposit = app(LoanService::class)->deposit($loan, 130000, CarbonImmutable::today(), 'CASH', $admin);
        $newer = Loan::factory()->create(['customer_id' => $loan->customer_id, 'status' => LoanStatus::Active]);

        $this->postJson("/api/v1/loans/{$loan->id}/transactions/{$deposit->id}/reverse", ['reason' => 'Undo'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => "The loan was settled and the customer has borrowed again (loan {$newer->loan_number}); the settlement cannot be reversed."]);
    }

    public function test_closed_period_with_distributed_profit_blocks_and_without_distribution_posts_today(): void
    {
        $admin = $this->signInAdmin();
        $loans = app(LoanService::class);
        $lastMonth = CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();

        $distributed = $this->activeLoan($admin);
        $blocked = $loans->deposit($distributed, 10000, $lastMonth->addDays(3), 'CASH', $admin);
        $open = $this->activeLoan($admin);
        $allowed = $loans->deposit($open, 10000, $lastMonth->addDays(4), 'CASH', $admin);

        AccountingPeriod::create(['company_id' => $admin->company_id, 'period_start' => $lastMonth, 'period_end' => $lastMonth->endOfMonth(), 'status' => AccountingPeriod::STATUS_CLOSED, 'closed_at' => now()]);
        $this->actingAs($this->secondApprover($admin));

        $this->approveReversal($this->postJson("/api/v1/loans/{$open->id}/transactions/{$allowed->id}/reverse", ['reason' => 'Posted twice']))
            ->assertOk()
            ->assertJsonFragment(['message' => 'Repayment reversed successfully. TZS 10,000 returned to suspense (receipt '.Payment::sole()->receipt_number.'). The repayment belongs to the closed period '.$lastMonth->format('Y-m').'; the reversal was posted today as an adjustment in the current open period.']);
        $this->assertSame(today()->toDateString(), JournalEntry::find($allowed->fresh()->reversal_journal_entry_id)->entry_date->toDateString());

        DividendDeclaration::create(['company_id' => $admin->company_id, 'period' => $lastMonth, 'profit_amount' => 1000, 'reinvest_percent' => 70, 'reinvest_amount' => 700, 'dividend_percent' => 30, 'dividend_amount' => 300]);

        $this->postJson("/api/v1/loans/{$distributed->id}/transactions/{$blocked->id}/reverse", ['reason' => 'Posted twice'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'Profit for the closed period '.$lastMonth->format('Y-m').' has already been distributed (dividend declaration); this repayment cannot be reversed.']);
        $this->assertNull($blocked->fresh()->reversed_at);
    }

    public function test_permission_and_company_scope(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);
        $deposit = app(LoanService::class)->deposit($loan, 10000, CarbonImmutable::today(), 'CASH', $admin);
        $url = "/api/v1/loans/{$loan->id}/transactions/{$deposit->id}/reverse";

        foreach (['teller', 'loan_officer', 'branch_manager'] as $role) {
            $this->actingAs($this->employeeWithRole($admin, $role))->postJson($url, ['reason' => 'Undo'])->assertForbidden();
        }
        $this->actingAs($this->employeeWithRole($admin, 'finance'))->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $otherCompany = Company::factory()->create();
        app(AccessControl::class)->seedRoles($otherCompany);
        $outsider = Employee::factory()->create(['company_id' => $otherCompany->id, 'role_id' => $otherCompany->roles()->where('key', 'finance')->value('id')]);
        $this->actingAs($outsider)->postJson($url, ['reason' => 'Undo'])->assertNotFound();

        $otherLoan = $this->activeLoan($admin);
        $this->actingAs($admin)->postJson("/api/v1/loans/{$otherLoan->id}/transactions/{$deposit->id}/reverse", ['reason' => 'Undo'])->assertNotFound();

        $this->approveReversal($this->actingAs($this->employeeWithRole($admin, 'finance'))->postJson($url, ['reason' => 'Undo']), $this->employeeWithRole($admin, 'finance'))->assertOk();
    }

    public function test_nothing_changes_when_the_ledger_fails_part_way(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin, penalty: 5000);
        $payment = $this->unmatchedPayment($admin, 105000);
        app(PaymentService::class)->allocateSuspense($payment, $loan, 105000, $admin);
        $deposit = $loan->transactions()->where('type', 'deposit')->sole();
        $approver = $this->secondApprover($admin);
        $entries = JournalEntry::count();
        $before = $this->snapshot($admin);

        $real = new Ledger;
        $this->app->instance(Ledger::class, \Mockery::mock(Ledger::class, function ($mock) use ($real): void {
            $mock->shouldReceive('reverse')->andReturnUsing(fn (...$arguments) => $real->reverse(...$arguments));
            $mock->shouldReceive('balance')->andReturnUsing(fn (...$arguments) => $real->balance(...$arguments));
            $mock->shouldReceive('journal')->andReturnUsing(fn (...$arguments) => str_starts_with($arguments[1], 'REPAYMENT REVERSED TO SUSPENSE')
                ? throw new RuntimeException('Ledger unavailable')
                : $real->journal(...$arguments));
        }));
        $this->app->forgetInstance(LoanService::class);
        $this->app->forgetInstance(PaymentService::class);

        try {
            app(LoanService::class)->reverseRepayment($deposit, 'Ledger failure', $approver);
            $this->fail('The reversal should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Ledger unavailable', $exception->getMessage());
        }

        $this->assertNull($deposit->fresh()->reversed_at);
        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame($before, $this->snapshot($admin));
        $this->assertEquals(5000, Penalty::where('loan_id', $loan->id)->sole()->paid_amount);
        $this->assertSame(1, PenaltyPayment::count());
        $this->assertEquals(100000, $loan->schedules()->sum('paid_amount'));
        $this->assertSame(PaymentStatus::Allocated, $payment->fresh()->status);
    }

    public function test_sequential_and_stale_repayments_cannot_over_allocate(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);
        $loans = app(LoanService::class);
        $stale = Loan::find($loan->id);
        $staleAllocation = $loans->allocate($stale, 100000);

        $loans->deposit($loan, 100000, CarbonImmutable::today(), 'CASH', $admin);

        try {
            $loans->deposit($stale, $staleAllocation['principal'], CarbonImmutable::today(), 'CASH', $admin);
            $this->fail('A second repayment beyond the balance must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Amount exceeds the outstanding balance of 30,000.'], $exception->errors()['depost']);
        }

        $payment = $this->unmatchedPayment($admin, 100000);
        app(PaymentService::class)->allocateSuspense($payment, $stale, 30000, $admin);
        $this->assertSame(1, $loan->transactions()->where('type', 'deposit')->where('amount', 30000)->count());
        $this->assertSame(LoanStatus::Closed, $loan->fresh()->status);

        $this->expectException(ValidationException::class);
        app(PaymentService::class)->allocateSuspense($payment->fresh(), $stale, 1000, $admin);
    }

    public function test_write_off_records_the_principal_written_off_in_the_ledger(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin, penalty: 4000);
        app(LoanService::class)->deposit($loan, 40000, CarbonImmutable::today(), 'CASH', $admin);
        $loan->update(['status' => LoanStatus::Default]);

        $requestId = $this->postJson("/api/v1/loans/{$loan->id}/write-off")->assertCreated()->json('write_off_request.id');
        $this->approveAsSecondUser($admin, "/api/v1/loans/write-off-requests/{$requestId}/approve")
            ->assertJsonPath('write_off.amount', 94000)
            ->assertJsonPath('write_off.principal_amount', 60000)
            ->assertJsonPath('write_off.penalty_amount', 4000)
            ->assertJsonPath('write_off.interest_amount', 30000);

        $this->assertEquals(60000, $loan->writeOff->principal_amount);
        $this->assertSame(60000.0, $this->balance($admin, Account::WriteOffExpense, $admin->branch_id));
        $this->getJson("/api/v1/loans/{$loan->id}")->assertOk()->assertJsonPath('data.write_off.principal_amount', 60000);
        $this->postJson("/api/v1/loans/{$loan->id}/write-off")->assertUnprocessable();

        $this->expectException(ValidationException::class);
        app(LoanService::class)->writeOff($loan->fresh(), $admin);
    }

    private function unmatchedPayment(Employee $admin, float $amount): Payment
    {
        return app(PaymentService::class)->recordUnmatched($admin->company_id, [
            'amount' => $amount, 'channel' => 'MPESA', 'transaction_id' => 'DEVFLOW'.uniqid(), 'paid_on' => today()->toDateString(), 'branch_id' => $admin->branch_id,
        ], $admin);
    }

    /**
     * @return array<string, float>
     */
    private function snapshot(Employee $admin): array
    {
        return collect(self::BRANCH_ACCOUNTS)->mapWithKeys(fn (Account $account): array => [$account->value => $this->balance($admin, $account, $admin->branch_id)])->all();
    }
}
