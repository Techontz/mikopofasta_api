<?php

namespace Tests\Feature\Approvals;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanRecovery;
use App\Models\Payment;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Ledger;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesSecondApprover;
use Tests\Feature\Api\Loans\BuildsServiceLoans;
use Tests\Feature\Api\Payments\InteractsWithRepayments;
use Tests\TestCase;

/**
 * Rule 6 on the loan reversals (repayment, disbursement, write-off recovery): the employee who posted the journal does not
 * reverse it — Super Admin included — unless approvals.self_approve is granted explicitly. The loan detail flags carry the
 * reason for the poster. Dividend payment reversals are covered in DividendApiTest.
 */
class ReversalSegregationTest extends TestCase
{
    use BuildsServiceLoans;
    use InteractsWithRepayments;
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-10 09:00:00'));
        $this->admin = $this->signInAdmin();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reversals(): array
    {
        return ['repayment' => ['repayment'], 'disbursement' => ['disbursement'], 'recovery' => ['recovery']];
    }

    #[DataProvider('reversals')]
    public function test_the_super_admin_poster_is_blocked_with_the_reason_and_nothing_changes(string $kind): void
    {
        $this->assertSame('super_admin', $this->admin->role->key, 'Super Admin does not get self-approval implicitly');
        $scenario = $this->scenario($kind);
        $this->assertSame($this->admin->id, JournalEntry::findOrFail($scenario['entry_id'])->employee_id);
        $entries = JournalEntry::count();
        $payments = Payment::count();
        $audits = AuditLog::count();

        $this->getJson($scenario['detail'])->assertOk()
            ->assertJsonPath($scenario['can'], false)
            ->assertJsonPath($scenario['reason'], SegregationOfDuties::REVERSER_MESSAGE);

        $this->postJson($scenario['url'], ['reason' => 'DEVFLOW mistake'])->assertForbidden()
            ->assertJsonPath('message', SegregationOfDuties::REVERSER_MESSAGE);

        $this->assertFalse($scenario['reversed'](), 'nothing is reversed');
        $this->assertSame($entries, JournalEntry::count(), 'no journal entry is posted');
        $this->assertSame($payments, Payment::count(), 'no money is returned to suspense');
        $this->assertSame($audits, AuditLog::count(), 'no audit or workflow record is written');
        $this->assertNull(JournalEntry::where('reversal_of_id', $scenario['entry_id'])->first());
    }

    #[DataProvider('reversals')]
    public function test_another_authorised_user_reverses(string $kind): void
    {
        $scenario = $this->scenario($kind);
        $approver = $this->secondApprover($this->admin);
        $this->actingAs($approver);

        $this->getJson($scenario['detail'])->assertOk()
            ->assertJsonPath($scenario['can'], true)
            ->assertJsonPath($scenario['reason'], null);
        $checker = $kind === 'recovery' ? $approver : $this->secondApprover($this->admin);
        $this->reverse($kind, $scenario, $checker)->assertOk();

        $this->assertTrue($scenario['reversed']());
        $this->assertSame($checker->id, JournalEntry::where('reversal_of_id', $scenario['entry_id'])->sole()->employee_id);
    }

    #[DataProvider('reversals')]
    public function test_the_poster_with_an_explicit_self_approval_grant_reverses(string $kind): void
    {
        $scenario = $this->scenario($kind);
        $this->grantSelfApproval($this->admin);

        $this->getJson($scenario['detail'])->assertOk()
            ->assertJsonPath($scenario['can'], true)
            ->assertJsonPath($scenario['reason'], null);
        $this->reverse($kind, $scenario)->assertOk();

        $this->assertTrue($scenario['reversed']());
    }

    public function test_the_recoveries_list_shows_the_reason_to_the_poster_only(): void
    {
        $scenario = $this->scenario('recovery');
        $url = preg_replace('#/\d+/reverse$#', '', $scenario['url']);

        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.0.can_reverse', false)
            ->assertJsonPath('data.0.reverse_blocked_reason', SegregationOfDuties::REVERSER_MESSAGE);
        $this->actingAs($this->secondApprover($this->admin))->getJson($url)->assertOk()
            ->assertJsonPath('data.0.can_reverse', true)
            ->assertJsonPath('data.0.reverse_blocked_reason', null);
    }

    public function test_business_blockers_come_before_the_segregation_reason(): void
    {
        $scenario = $this->scenario('repayment');
        $this->postJson($scenario['url'], ['reason' => 'DEVFLOW mistake'])->assertForbidden();
        $this->actingAs($this->secondApprover($this->admin));
        $this->reverse('repayment', $scenario)->assertOk();

        $this->actingAs($this->admin)->getJson($scenario['detail'])->assertOk()
            ->assertJsonPath($scenario['can'], false)
            ->assertJsonPath($scenario['reason'], 'This repayment has already been reversed.');
    }

    /**
     * POST the reverse endpoint; repayment and disbursement reversals only raise a request, approved here by another user.
     *
     * @param  array{url: string}  $scenario
     */
    private function reverse(string $kind, array $scenario, ?Employee $checker = null): TestResponse
    {
        $response = $this->postJson($scenario['url'], ['reason' => 'DEVFLOW mistake']);

        return $kind === 'recovery' ? $response : $this->approveReversal($response, $checker);
    }

    /**
     * A posted transaction of the signed-in Super Admin, with its reverse endpoint and loan detail flag paths.
     *
     * @return array{url: string, detail: string, can: string, reason: string, entry_id: int, reversed: Closure(): bool}
     */
    private function scenario(string $kind): array
    {
        return match ($kind) {
            'repayment' => $this->repayment(),
            'disbursement' => $this->disbursement(),
            'recovery' => $this->recovery(),
        };
    }

    /**
     * @return array{url: string, detail: string, can: string, reason: string, entry_id: int, reversed: Closure(): bool}
     */
    private function repayment(): array
    {
        $loan = $this->activeLoan($this->admin);
        $deposit = app(LoanService::class)->deposit($loan, 20000, CarbonImmutable::today(), 'CASH', $this->admin);

        return [
            'url' => "/api/v1/loans/{$loan->id}/transactions/{$deposit->id}/reverse",
            'detail' => "/api/v1/loans/{$loan->id}",
            'can' => 'data.transactions.0.can_reverse',
            'reason' => 'data.transactions.0.reverse_blocked_reason',
            'entry_id' => (int) $deposit->journal_entry_id,
            'reversed' => fn (): bool => $deposit->fresh()->reversed_at !== null,
        ];
    }

    /**
     * @return array{url: string, detail: string, can: string, reason: string, entry_id: int, reversed: Closure(): bool}
     */
    private function disbursement(): array
    {
        // The lending cash lives in the HQ PRINCIPAL A/C (no branch): the customer borrows at a branch, HQ pays.
        app(Ledger::class)->openingBalance($this->admin->company_id, Account::Principal, 500000);
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'status' => 'pending']);
        $loan = Loan::factory()->create(['customer_id' => $customer->id, 'amount_approved' => 100000, 'insurance' => 0, 'fee_deduct' => false, 'loan_fee' => 0, 'status' => LoanStatus::AwaitingDisbursement]);
        $entry = app(LoanService::class)->withdraw($loan, CarbonImmutable::today(), $this->admin, 'LOAN DISBURSEMENT', 'cash');
        LoanDisbursement::create([
            'company_id' => $loan->company_id, 'branch_id' => $loan->branch_id, 'loan_id' => $loan->id, 'batch_id' => 'CASH'.uniqid(), 'attempt' => 1,
            'channel' => 'cash', 'phone' => $customer->phone, 'amount' => 100000, 'status' => LoanDisbursement::SUCCESS, 'journal_entry_id' => $entry->id,
            'source_account' => LoanDisbursement::SOURCE_CASH,
        ]);

        return [
            'url' => "/api/v1/loans/{$loan->id}/reverse-disbursement",
            'detail' => "/api/v1/loans/{$loan->id}",
            'can' => 'data.can_reverse_disbursement',
            'reason' => 'data.reverse_disbursement_blocked_reason',
            'entry_id' => $entry->id,
            'reversed' => fn (): bool => $loan->fresh()->status === LoanStatus::Cancelled,
        ];
    }

    /**
     * @return array{url: string, detail: string, can: string, reason: string, entry_id: int, reversed: Closure(): bool}
     */
    private function recovery(): array
    {
        $loan = $this->serviceLoan($this->admin);
        app(LoanService::class)->writeOff($loan->fresh(), $this->admin);
        $recoveryId = $this->postJson("/api/v1/loans/{$loan->id}/recoveries", ['amount' => 10000, 'method' => 'CASH', 'reference' => 'DEVFLOW-REC'])->assertCreated()->json('data.recovery_id');
        $recovery = LoanRecovery::findOrFail($recoveryId);

        return [
            'url' => "/api/v1/loans/{$loan->id}/recoveries/{$recovery->id}/reverse",
            'detail' => "/api/v1/loans/{$loan->id}",
            'can' => 'data.recoveries.0.can_reverse',
            'reason' => 'data.recoveries.0.reverse_blocked_reason',
            'entry_id' => (int) $recovery->journal_entry_id,
            'reversed' => fn (): bool => $recovery->fresh()->reversed_at !== null,
        ];
    }
}
