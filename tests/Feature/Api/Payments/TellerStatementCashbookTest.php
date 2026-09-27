<?php

namespace Tests\Feature\Api\Payments;

use App\Enums\Account;
use App\Services\LoanService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * Teller page statement "Remaining Debt" ends at the authoritative outstanding; the cashbook is ledger-only with
 * unconfirmed teller cash apart.
 */
class TellerStatementCashbookTest extends TestCase
{
    use InteractsWithRepayments;
    use RefreshDatabase;
    use UsesSecondApprover;

    public function test_statement_remaining_debt_follows_components_penalties_and_ends_at_the_outstanding_total(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin, penalty: 10000);
        $loans = app(LoanService::class);

        $loans->deposit($loan, 50000, CarbonImmutable::today()->subDays(5), employee: $admin);
        $loans->deposit($loan, 20000, CarbonImmutable::today(), employee: $admin);
        $payment = app(PaymentService::class)->recordUnmatched($admin->company_id, [
            'amount' => 5000, 'channel' => 'MPESA', 'transaction_id' => 'DEVFLOW'.uniqid(), 'paid_on' => today()->toDateString(), 'branch_id' => $admin->branch_id,
        ], $admin);
        app(PaymentService::class)->allocateSuspense($payment, $loan, 5000, $admin);
        $loans->reverseRepayment($loan->transactions()->where('type', 'deposit')->latest('id')->firstOrFail(), 'Wrong loan', $this->secondApprover($admin));

        $outstanding = $loans->outstanding($loan->fresh());
        $this->assertSame(70000.0, $outstanding['total']);

        $response = $this->getJson("/api/v1/teller/customers/{$loan->customer_id}")->assertOk();
        $statement = $response->json('data.statement');

        $this->assertCount(3, $statement);
        $this->assertSame(80000, (int) $statement[0]['remain'], 'before the penalty date: 50,000 principal + 30,000 interest left');
        $this->assertSame(70000, (int) $statement[1]['remain'], '30,000 principal + 10,000 penalty + 30,000 interest');
        $this->assertTrue($statement[2]['reversed']);
        $this->assertSame($outstanding['total'], (float) $statement[2]['remain'], 'the reversed repayment is not counted');
        $this->assertSame($outstanding['total'], (float) $response->json('data.loan.remaining_debt'));
        $this->assertSame(70000, (int) $statement[2]['balance']);
    }

    public function test_statement_last_row_includes_a_penalty_charged_after_the_last_transaction(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin, penalty: 10000);
        app(LoanService::class)->deposit($loan, 40000, CarbonImmutable::today()->subDays(6), employee: $admin);

        $statement = $this->getJson("/api/v1/teller/customers/{$loan->customer_id}")->assertOk()->json('data.statement');

        $this->assertSame(100000, (int) $statement[0]['remain']);
        $this->assertSame(100000.0, app(LoanService::class)->outstanding($loan->fresh())['total']);
    }

    public function test_cashbook_is_ledger_only_ignores_same_day_reversals_and_lists_pending_cash_apart(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);
        $loans = app(LoanService::class);
        $payments = app(PaymentService::class);

        $loans->deposit($loan, 50000, CarbonImmutable::today()->subDays(3), employee: $admin);
        $loans->deposit($loan, 20000, CarbonImmutable::today(), employee: $admin);
        $unmatched = $payments->recordUnmatched($admin->company_id, [
            'amount' => 5000, 'channel' => 'MPESA', 'transaction_id' => 'DEVFLOW'.uniqid(), 'paid_on' => today()->toDateString(), 'branch_id' => $admin->branch_id,
        ], $admin);
        $payments->allocateSuspense($unmatched, $loan, 5000, $admin);
        $loans->reverseRepayment($loan->transactions()->where('type', 'deposit')->latest('id')->firstOrFail(), 'Wrong loan', $this->secondApprover($admin));
        $payments->recordCash($loan->fresh(), 7000, 'CASH', $admin);

        $cashbook = $this->getJson("/api/v1/teller/customers/{$loan->customer_id}")->assertOk()->json('data.cashbook');

        $this->assertSame(50000, (int) $cashbook['opening']);
        $this->assertSame(20000, (int) $cashbook['deposit'], 'the repayment reversed today and its reversal are left out');
        $this->assertSame(100000, (int) $cashbook['withdrawal']);
        $this->assertSame(-30000, (int) $cashbook['closing']);
        $this->assertSame((float) $cashbook['closing'], $this->balance($admin, Account::Principal), 'the cashbook closes on the HQ PRINCIPAL A/C the branch lends from');
        $this->assertSame(7000, (int) $cashbook['pending_cash'], 'unconfirmed teller cash is not in the closing balance');
    }
}
