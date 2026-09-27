<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanOffset;
use App\Models\Payment;
use App\Models\Penalty;
use App\Services\Ledger;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\Feature\Api\Payments\InteractsWithRepayments;
use Tests\TestCase;

/**
 * Loan disbursement reversal (spec §21, §23): only without dependent records; restores the source account and the fee.
 */
class LoanDisbursementReversalTest extends TestCase
{
    use InteractsWithRepayments;
    use RefreshDatabase;
    use UsesSecondApprover;

    public function test_cash_disbursement_reversal_restores_principal_and_fee_accounts_and_cancels_the_loan(): void
    {
        $admin = $this->signInAdmin();
        app(Ledger::class)->openingBalance($admin->company_id, Account::Principal, 500000);
        $before = $this->balances($admin);
        $loan = $this->disbursedLoan($admin);

        $this->assertSame(400000.0, $this->balance($admin, Account::Principal));
        $this->assertSame(5000.0, $this->balance($admin, Account::FeeIncome, $admin->branch_id));
        $this->actingAs($approver = $this->secondApprover($admin));
        $this->getJson("/api/v1/loans/{$loan->id}")->assertOk()->assertJsonPath('data.can_reverse_disbursement', true);

        $requested = $this->postJson("/api/v1/loans/{$loan->id}/reverse-disbursement", ['reason' => 'DEVFLOW customer returned the cash']);
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status, 'a request posts nothing');
        $this->approveReversal($requested)
            ->assertOk()
            ->assertJsonPath('message', 'Loan disbursement reversed successfully; the loan is cancelled.')
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame($before, $this->balances($admin));
        $loan->refresh();
        $this->assertSame(LoanStatus::Cancelled, $loan->status);
        $this->assertSame('DEVFLOW customer returned the cash', $loan->decision_reason);
        $this->assertSame(1, $loan->schedules()->count(), 'Schedules are kept');
        $disbursement = LoanDisbursement::where('loan_id', $loan->id)->sole();
        $this->assertNotNull($disbursement->reversed_at);
        $this->assertSame($disbursement->journal_entry_id, JournalEntry::findOrFail($disbursement->reversal_journal_entry_id)->reversal_of_id);
        $this->assertNotNull($loan->transactions()->where('type', 'withdrawal')->sole()->reversed_at);
        $this->assertTrue(AuditLog::where('action', 'DISBURSEMENT_REVERSED')->where('auditable_id', $loan->id)->exists());
        $this->assertSame('pending', $loan->customer->fresh()->status);

        $this->postJson("/api/v1/loans/{$loan->id}/reverse-disbursement", ['reason' => 'again'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_bank_disbursement_reversal_restores_the_bank_account(): void
    {
        $admin = $this->signInAdmin();
        $bank = BankAccount::create(['company_id' => $admin->company_id, 'name' => 'NMB']);
        app(Ledger::class)->openingBalance($admin->company_id, Account::Bank, 300000, bankAccount: $bank->id);
        $loan = $this->disbursedLoan($admin, ['account' => Account::Bank, 'bank' => $bank->id]);
        $this->assertSame(205000.0, $this->balance($admin, Account::Bank, bankAccountId: $bank->id));

        $this->approveReversal($this->actingAs($this->secondApprover($admin))->postJson("/api/v1/loans/{$loan->id}/reverse-disbursement", ['reason' => 'Sent to wrong number']))->assertOk();

        $this->assertSame(300000.0, $this->balance($admin, Account::Bank, bankAccountId: $bank->id));
        $this->assertSame(0.0, $this->balance($admin, Account::FeeIncome, $admin->branch_id));
        $this->assertSame(0.0, $this->balance($admin, Account::LoanReceivable, $admin->branch_id));
    }

    public function test_dependent_records_block_the_reversal(): void
    {
        $admin = $this->signInAdmin();
        app(Ledger::class)->openingBalance($admin->company_id, Account::Principal, 1000000);
        $approver = $this->secondApprover($admin);
        $reverse = fn (Loan $loan) => $this->actingAs($approver)->postJson("/api/v1/loans/{$loan->id}/reverse-disbursement", ['reason' => 'Undo']);

        $repaid = $this->disbursedLoan($admin);
        $deposit = app(LoanService::class)->deposit($repaid, 10000, CarbonImmutable::today(), 'CASH', $admin);
        $reverse($repaid)->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'The loan has repayments; reverse them first (newest first).']);
        $this->approveReversal($this->actingAs($approver)->postJson("/api/v1/loans/{$repaid->id}/transactions/{$deposit->id}/reverse", ['reason' => 'Undo']))->assertOk();
        $this->approveReversal($reverse($repaid))->assertOk();

        $penalised = $this->disbursedLoan($admin);
        Penalty::create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'customer_id' => $penalised->customer_id, 'loan_id' => $penalised->id, 'amount' => 1000, 'penalty_date' => today()]);
        $reverse($penalised)->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'The loan has penalties; its disbursement cannot be reversed.']);

        $toppedUp = $this->disbursedLoan($admin);
        Loan::factory()->create(['customer_id' => $toppedUp->customer_id, 'topup_of_loan_id' => $toppedUp->id]);
        $reverse($toppedUp)->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'Another loan is a top-up of this loan; its disbursement cannot be reversed.']);

        $closed = $this->disbursedLoan($admin);
        $closed->update(['status' => LoanStatus::Default]);
        $reverse($closed)->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'Only an active or overdue loan can have its disbursement reversed (the loan is '.LoanStatus::Default->label().').']);

        $this->getJson("/api/v1/loans/{$penalised->id}")->assertOk()
            ->assertJsonPath('data.can_reverse_disbursement', false)
            ->assertJsonPath('data.reverse_disbursement_blocked_reason', 'The loan has penalties; its disbursement cannot be reversed.');
    }

    public function test_a_top_up_reversal_also_reverses_its_settlement_of_the_previous_loan(): void
    {
        $admin = $this->signInAdmin();
        app(Ledger::class)->openingBalance($admin->company_id, Account::Principal, 1000000);
        $previous = $this->disbursedLoan($admin);
        $before = $this->balances($admin);
        $suspense = $this->balance($admin, Account::Suspense, $admin->branch_id);
        $payments = Payment::count();

        $topup = $this->disbursedLoan($admin);
        $topup->update(['customer_id' => $previous->customer_id, 'topup_of_loan_id' => $previous->id]);
        $balance = app(LoanService::class)->outstanding($previous)['total'];
        $settlement = app(LoanService::class)->deposit($previous, $balance, CarbonImmutable::today(), 'TOPUP', $admin);
        $offset = LoanOffset::create([
            'company_id' => $previous->company_id, 'branch_id' => $previous->branch_id, 'customer_id' => $previous->customer_id, 'old_loan_id' => $previous->id,
            'new_loan_id' => $topup->id, 'loan_transaction_id' => $settlement->id, 'amount' => $settlement->amount, 'principal_amount' => $settlement->principal,
            'penalty_amount' => 0, 'interest_amount' => $settlement->interest, 'salary_advance_amount' => 0, 'insurance_amount' => 0, 'cash_disbursed' => 0, 'settled_on' => today(),
        ]);
        $this->assertSame(LoanStatus::Closed, $previous->fresh()->status);

        $approver = $this->secondApprover($admin);
        $this->actingAs($approver)->postJson("/api/v1/loans/{$previous->id}/transactions/{$settlement->id}/reverse", ['reason' => 'Undo'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => "This repayment is the settlement made by top-up loan {$topup->loan_number}; reverse that loan's disbursement and this settlement is reversed with it."]);
        $this->getJson('/api/v1/reports/cash')->assertOk();

        $this->approveReversal($this->actingAs($approver)->postJson("/api/v1/loans/{$topup->id}/reverse-disbursement", ['reason' => 'Top-up sent in error']))
            ->assertOk()
            ->assertJsonPath('message', "Loan disbursement reversed successfully; the loan is cancelled. The top-up settlement of loan {$previous->loan_number} was reversed too; that loan is open again.");

        $this->assertSame(LoanStatus::Cancelled, $topup->fresh()->status);
        $this->assertSame(LoanStatus::Active, $previous->fresh()->status);
        $this->assertEquals($balance, app(LoanService::class)->outstanding($previous->fresh())['total'], 'the previous loan owes its balance again');
        $this->assertNotNull($settlement->fresh()->reversed_at);
        $this->assertNotNull($offset->fresh()->reversed_at);
        $this->assertSame($before, $this->balances($admin), 'the ledger is exactly as before the top-up');
        $this->assertSame($suspense, $this->balance($admin, Account::Suspense, $admin->branch_id), 'nothing goes to suspense: the settlement was paid by the top-up');
        $this->assertSame($payments, Payment::count());
        $this->assertTrue(AuditLog::where('action', 'TOPUP_SETTLEMENT_REVERSED')->where('auditable_id', $previous->id)->exists());
    }

    public function test_permission_and_company_scope(): void
    {
        $admin = $this->signInAdmin();
        app(Ledger::class)->openingBalance($admin->company_id, Account::Principal, 500000);
        $loan = $this->disbursedLoan($admin);
        $url = "/api/v1/loans/{$loan->id}/reverse-disbursement";

        foreach (['teller', 'loan_officer', 'branch_manager', 'credit_officer'] as $role) {
            $this->actingAs($this->employeeWithRole($admin, $role))->postJson($url, ['reason' => 'Undo'])->assertForbidden();
        }

        $other = $this->signInAdmin();
        $this->actingAs($other)->postJson($url, ['reason' => 'Undo'])->assertNotFound();

        $this->approveReversal($this->actingAs($this->employeeWithRole($admin, 'admin'))->postJson($url, ['reason' => 'Undo']), $this->employeeWithRole($admin, 'finance'))->assertOk();
    }

    /**
     * A 100,000 loan (5,000 fee deducted) disbursed through LoanService::withdraw() with a successful batch.
     *
     * @param  array{account: Account, branch?: int|null, bank?: int|null}|null  $source
     */
    private function disbursedLoan(Employee $admin, ?array $source = null): Loan
    {
        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'status' => 'pending']);
        $loan = Loan::factory()->create([
            'customer_id' => $customer->id,
            'amount_approved' => 100000,
            'insurance' => 0,
            'fee_deduct' => true,
            'loan_fee' => 5000,
            'status' => LoanStatus::AwaitingDisbursement,
        ]);
        $entry = app(LoanService::class)->withdraw($loan, CarbonImmutable::today(), $admin, 'LOAN DISBURSEMENT', 'cash', $source);
        LoanDisbursement::create([
            'company_id' => $loan->company_id, 'branch_id' => $loan->branch_id, 'loan_id' => $loan->id, 'batch_id' => 'CASH'.uniqid(), 'attempt' => 1,
            'channel' => 'cash', 'phone' => $customer->phone, 'amount' => 95000, 'status' => LoanDisbursement::SUCCESS, 'journal_entry_id' => $entry->id,
            'source_account' => ($source['account'] ?? Account::Principal) === Account::Bank ? LoanDisbursement::SOURCE_BANK : LoanDisbursement::SOURCE_CASH,
            'source_bank_account_id' => $source['bank'] ?? null,
        ]);

        return $loan->fresh();
    }

    /**
     * PRINCIPAL A/C is read at company level (HQ, no branch) — the lending cash never sits in a branch account; the fee
     * and receivable accounts stay tagged to the branch that originated the loan.
     *
     * @return array<string, float>
     */
    private function balances(Employee $admin): array
    {
        return collect([Account::Principal, Account::LoanReceivable, Account::LoanFee, Account::FeeIncome])
            ->mapWithKeys(fn (Account $account): array => [
                $account->value => $this->balance($admin, $account, $account === Account::Principal ? null : $admin->branch_id),
            ])
            ->all();
    }
}
