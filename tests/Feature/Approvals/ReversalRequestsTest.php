<?php

namespace Tests\Feature\Approvals;

use App\Enums\Account;
use App\Models\ApprovalPolicy;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Penalty;
use App\Models\PenaltyPayment;
use App\Models\ReversalRequest;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\LoanService;
use App\Services\ReversalRequests;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\Feature\Api\Payments\InteractsWithRepayments;
use Tests\TestCase;

/**
 * Maker/checker reversals (user ruling 2026-09-17): Finance requests the reversal of a repayment, a disbursement or a direct
 * penalty payment; nothing is posted until another Finance user, an Admin or the Super Admin approves it.
 */
class ReversalRequestsTest extends TestCase
{
    use InteractsWithRepayments;
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->signInAdmin();
    }

    public function test_a_finance_request_posts_nothing_and_another_finance_user_approves_it(): void
    {
        [$loan, $deposit] = $this->repaidLoan();
        $maker = $this->employeeWithRole($this->admin, 'finance');
        $entries = JournalEntry::count();

        $this->actingAs($maker)->postJson($this->repaymentUrl($loan, $deposit), ['reason' => 'Wrong customer'])
            ->assertCreated()
            ->assertJsonPath('message', 'Reversal request submitted. Nothing is posted until another Finance user, an Admin or the Super Admin approves it under Reversal Requests.');
        $request = ReversalRequest::sole();

        $this->assertSame(ReversalRequest::PENDING, $request->status);
        $this->assertSame(ReversalRequest::REPAYMENT, $request->type);
        $this->assertEquals(30000, $request->amount);
        $this->assertNull($deposit->fresh()->reversed_at);
        $this->assertSame($entries, JournalEntry::count());

        $this->getJson("/api/v1/loans/{$loan->id}")->assertOk()
            ->assertJsonPath('data.transactions.0.can_reverse', false)
            ->assertJsonPath('data.transactions.0.reverse_blocked_reason', ReversalRequests::PENDING_MESSAGE)
            ->assertJsonPath('data.transactions.0.reversal_request.id', $request->id)
            ->assertJsonPath('data.transactions.0.reversal_request.can_approve', false);
        $this->postJson($this->repaymentUrl($loan, $deposit), ['reason' => 'Again'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => ReversalRequests::PENDING_MESSAGE]);

        $this->getJson('/api/v1/reversal-requests')->assertOk()
            ->assertJsonPath('data.0.id', $request->id)
            ->assertJsonPath('data.0.can_approve', false)
            ->assertJsonPath('data.0.approve_blocked_reason', SegregationOfDuties::INITIATOR_MESSAGE)
            ->assertJsonPath('data.0.can_reject', true);
        $this->postJson("/api/v1/reversal-requests/{$request->id}/approve")->assertForbidden()
            ->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);
        $this->assertNull($deposit->fresh()->reversed_at);

        $checker = $this->employeeWithRole($this->admin, 'finance');
        $this->actingAs($checker)->getJson('/api/v1/approvals/pending')->assertOk()
            ->assertJsonFragment(['workflow' => ApprovalPolicy::REVERSAL_REQUESTS, 'label' => 'Reversals', 'count' => 1]);
        $this->postJson("/api/v1/reversal-requests/{$request->id}/approve")->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by', $checker->full_name);

        $deposit->refresh();
        $this->assertNotNull($deposit->reversed_at);
        $this->assertSame($checker->id, $deposit->reversed_by);
        $this->assertSame($deposit->reversal_journal_entry_id, $request->fresh()->reversal_journal_entry_id);
        $this->postJson("/api/v1/reversal-requests/{$request->id}/approve")->assertUnprocessable();
    }

    public function test_cash_transaction_report_offers_the_reversal_request_with_the_loan_page_eligibility(): void
    {
        [$loan, $deposit] = $this->repaidLoan();
        $maker = $this->employeeWithRole($this->admin, 'finance');
        $row = fn ($response, int $id) => collect($response->json('data.rows'))->firstWhere('id', $id);

        $before = $this->actingAs($maker)->getJson('/api/v1/reports/cash')->assertOk();
        $this->assertSame(['reversal_type' => 'loan_repayment', 'may_reverse' => true, 'can_reverse' => true, 'reverse_blocked_reason' => null],
            array_intersect_key($row($before, $deposit->id), array_flip(['reversal_type', 'may_reverse', 'can_reverse', 'reverse_blocked_reason'])));
        $withdrawal = collect($before->json('data.rows'))->firstWhere('withdrawal', '!=', null);
        if ($withdrawal !== null) {
            $this->assertSame('loan_disbursement', $withdrawal['reversal_type']);
        }

        $this->postJson($this->repaymentUrl($loan, $deposit), ['reason' => 'Wrong customer'])->assertCreated();
        $after = $this->getJson('/api/v1/reports/cash')->assertOk();
        $this->assertFalse($row($after, $deposit->id)['can_reverse']);
        $this->assertSame(ReversalRequests::PENDING_MESSAGE, $row($after, $deposit->id)['reverse_blocked_reason']);

        $teller = $this->employeeWithRole($this->admin, 'branch_manager');
        $viewer = $this->actingAs($teller)->getJson('/api/v1/reports/cash');
        if ($viewer->status() === 200 && $row($viewer, $deposit->id) !== null) {
            $this->assertFalse($row($viewer, $deposit->id)['may_reverse']);
        }
    }

    public function test_admin_and_super_admin_approve_but_branch_roles_cannot(): void
    {
        [$loan, $deposit] = $this->repaidLoan();
        $this->actingAs($this->employeeWithRole($this->admin, 'finance'))->postJson($this->repaymentUrl($loan, $deposit), ['reason' => 'Wrong loan'])->assertCreated();
        $request = ReversalRequest::sole();

        foreach (['branch_manager', 'teller', 'loan_officer'] as $role) {
            $this->actingAs($this->employeeWithRole($this->admin, $role))->postJson("/api/v1/reversal-requests/{$request->id}/approve")->assertForbidden();
        }
        $this->actingAs($this->employeeWithRole($this->admin, 'admin'))->postJson("/api/v1/reversal-requests/{$request->id}/approve")->assertOk();
        $this->assertNotNull($deposit->fresh()->reversed_at);

        $disbursed = $this->activeLoan($this->admin);
        $this->actingAs($this->employeeWithRole($this->admin, 'finance'))->postJson("/api/v1/loans/{$disbursed->id}/reverse-disbursement", ['reason' => 'Sent twice'])->assertCreated();
        $this->actingAs($this->secondApprover($this->admin))->postJson('/api/v1/reversal-requests/'.ReversalRequest::latest('id')->value('id').'/approve')
            ->assertOk()->assertJsonPath('message', 'Loan disbursement reversed successfully; the loan is cancelled.');
    }

    public function test_the_super_admin_may_approve_their_own_request_but_never_a_reversal_of_their_own_posting(): void
    {
        $loan = $this->activeLoan($this->admin);
        $deposit = app(LoanService::class)->deposit($loan, 30000, CarbonImmutable::today(), 'CASH', $this->employeeWithRole($this->admin, 'finance'));

        $this->postJson($this->repaymentUrl($loan, $deposit), ['reason' => 'Wrong loan'])->assertCreated();
        $this->postJson('/api/v1/reversal-requests/'.ReversalRequest::sole()->id.'/approve')->assertOk();
        $this->assertNotNull($deposit->fresh()->reversed_at);

        $own = app(LoanService::class)->deposit($this->activeLoan($this->admin), 10000, CarbonImmutable::today(), 'CASH', $this->admin);
        $this->postJson($this->repaymentUrl($own->loan, $own), ['reason' => 'Mine'])->assertForbidden()
            ->assertJsonPath('message', SegregationOfDuties::REVERSER_MESSAGE);
    }

    public function test_a_rejected_request_posts_nothing_and_can_be_raised_again(): void
    {
        [$loan, $deposit] = $this->repaidLoan();
        $maker = $this->employeeWithRole($this->admin, 'finance');
        $this->actingAs($maker)->postJson($this->repaymentUrl($loan, $deposit), ['reason' => 'Wrong loan'])->assertCreated();
        $request = ReversalRequest::sole();

        $this->actingAs($this->employeeWithRole($this->admin, 'admin'))->postJson("/api/v1/reversal-requests/{$request->id}/reject", [])->assertUnprocessable();
        $this->postJson("/api/v1/reversal-requests/{$request->id}/reject", ['reason' => 'The repayment is correct'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'The repayment is correct');
        $this->assertNull($deposit->fresh()->reversed_at);

        $this->actingAs($maker)->getJson('/api/v1/reversal-requests?status=rejected')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson($this->repaymentUrl($loan, $deposit), ['reason' => 'Wrong loan, confirmed by the customer'])->assertCreated();
        $this->assertSame(2, ReversalRequest::count());
    }

    public function test_a_direct_penalty_payment_reversal_restores_the_penalty_and_the_ledger(): void
    {
        $loan = $this->activeLoan($this->admin, penalty: 8000);
        $penalty = Penalty::where('loan_id', $loan->id)->sole();
        $cashier = $this->employeeWithRole($this->admin, 'finance');
        $before = [$this->balance($this->admin, Account::Penalty, $this->admin->branch_id), $this->balance($this->admin, Account::PenaltyIncome, $this->admin->branch_id)];

        $this->actingAs($cashier)->postJson("/api/v1/penalties/{$penalty->id}/pay", ['penart_paid' => 5000])->assertOk();
        $payment = PenaltyPayment::sole();
        $this->assertNotNull($payment->journal_entry_id);
        $this->assertEquals(5000, $penalty->fresh()->paid_amount);

        $this->postJson("/api/v1/penalties/payments/{$payment->id}/reverse", ['reason' => 'Mine'])->assertForbidden();

        $maker = $this->employeeWithRole($this->admin, 'finance');
        $this->actingAs($maker)->getJson('/api/v1/penalties/paid')->assertOk()
            ->assertJsonPath('data.0.can_reverse', true)
            ->assertJsonPath('data.0.source', 'direct');
        $this->postJson("/api/v1/penalties/payments/{$payment->id}/reverse", ['reason' => 'Paid on the wrong penalty'])->assertCreated();
        $this->assertEquals(5000, $penalty->fresh()->paid_amount, 'a request posts nothing');
        $this->getJson('/api/v1/penalties/paid')->assertOk()
            ->assertJsonPath('data.0.can_reverse', false)
            ->assertJsonPath('data.0.reversal_request.type', ReversalRequest::PENALTY_PAYMENT);

        $checker = $this->employeeWithRole($this->admin, 'admin');
        $this->actingAs($checker)->postJson('/api/v1/reversal-requests/'.ReversalRequest::sole()->id.'/approve')->assertOk()
            ->assertJsonPath('message', 'Penalty payment reversed successfully. TZS 5,000 is owed on the penalty again.');

        $payment->refresh();
        $this->assertNotNull($payment->reversed_at, 'kept and marked, never deleted');
        $this->assertSame($checker->id, $payment->reversed_by);
        $this->assertSame($payment->journal_entry_id, JournalEntry::findOrFail($payment->reversal_journal_entry_id)->reversal_of_id);
        $this->assertEquals(0, $penalty->fresh()->paid_amount);
        $this->assertSame($before, [$this->balance($this->admin, Account::Penalty, $this->admin->branch_id), $this->balance($this->admin, Account::PenaltyIncome, $this->admin->branch_id)]);
        $this->assertEquals(8000, app(LoanService::class)->outstanding($loan->fresh())['penalty']);

        $this->actingAs($maker)->getJson('/api/v1/penalties/paid')->assertOk()
            ->assertJsonPath('data.0.reversed', true)
            ->assertJsonPath('data.0.reversed_by', $checker->full_name);
        $this->postJson("/api/v1/penalties/payments/{$payment->id}/reverse", ['reason' => 'Again'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'This penalty payment has already been reversed.']);
    }

    public function test_the_penalty_portion_of_a_repayment_is_reversed_with_the_repayment_only(): void
    {
        $loan = $this->activeLoan($this->admin, penalty: 4000);
        app(LoanService::class)->deposit($loan, 110000, CarbonImmutable::today(), 'CASH', $this->admin);
        $payment = PenaltyPayment::sole();
        $this->assertFalse($payment->isDirect());

        $this->actingAs($this->employeeWithRole($this->admin, 'finance'))->postJson("/api/v1/penalties/payments/{$payment->id}/reverse", ['reason' => 'Undo'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'This penalty was paid as part of a loan repayment; reverse the repayment instead.']);
    }

    public function test_only_finance_and_the_super_admin_request_penalty_payment_reversals_by_default(): void
    {
        $loan = $this->activeLoan($this->admin, penalty: 3000);
        app(LoanService::class)->payPenalty(Penalty::where('loan_id', $loan->id)->sole(), 3000, CarbonImmutable::today(), $this->employeeWithRole($this->admin, 'finance'));
        $url = '/api/v1/penalties/payments/'.PenaltyPayment::sole()->id.'/reverse';

        foreach (['admin', 'branch_manager', 'teller'] as $role) {
            $this->actingAs($this->employeeWithRole($this->admin, $role))->postJson($url, ['reason' => 'Undo'])->assertForbidden();
        }

        $outsider = $this->signInAdmin();
        $this->actingAs($outsider)->postJson($url, ['reason' => 'Undo'])->assertNotFound();
        $this->actingAs($this->admin)->postJson($url, ['reason' => 'Undo'])->assertCreated();
    }

    /**
     * @return array{Loan, LoanTransaction}
     */
    private function repaidLoan(): array
    {
        $loan = $this->activeLoan($this->admin);

        return [$loan, app(LoanService::class)->deposit($loan, 30000, CarbonImmutable::today(), 'CASH', $this->admin)];
    }

    private function repaymentUrl(Loan $loan, LoanTransaction $deposit): string
    {
        return "/api/v1/loans/{$loan->id}/transactions/{$deposit->id}/reverse";
    }
}
