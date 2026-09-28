<?php

namespace Tests\Feature\Api\Accounting;

use App\Enums\Account;
use App\Enums\TransactionType;
use App\Models\AccountingPeriod;
use App\Models\Capital;
use App\Models\FloatTransfer;
use App\Models\JournalEntry;
use App\Models\LoanTransaction;
use App\Models\Payment;
use App\Models\Saving;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class TransactionTypeTest extends TestCase
{
    use AccountingTestHelpers, RefreshDatabase;
    use UsesSecondApprover;

    public function test_ledger_infers_the_type_explicit_type_wins_and_reversal_is_typed(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);

        $opening = $ledger->openingBalance($admin->company_id, Account::Company, 500000);
        $this->assertSame(TransactionType::OpeningBalance, $opening->transaction_type);

        $floatId = $this->postJson('/api/v1/capital/floats', ['amount' => 1000, 'from_account' => Account::Company->value])->assertCreated()->json('data.id');
        $this->approveAsSecondUser($admin, "/api/v1/capital/floats/{$floatId}/approve");
        $float = JournalEntry::where('source_type', (new FloatTransfer)->getMorphClass())->firstOrFail();
        $this->assertSame(TransactionType::InternalTransfer, $float->transaction_type);

        $manual = $ledger->transfer($admin->company_id, ['account' => Account::Company], ['account' => Account::Bank], 100, 'MANUAL MOVE');
        $this->assertSame(TransactionType::Manual, $manual->transaction_type);

        $explicit = $ledger->transfer($admin->company_id, ['account' => Account::Company], ['account' => Account::Principal, 'branch' => $admin->branch_id], 100, 'ADJUST', type: TransactionType::Adjustment);
        $this->assertSame(TransactionType::Adjustment, $explicit->transaction_type);

        $reversal = $ledger->reverse($manual, 'Wrong account');
        $this->assertSame(TransactionType::Reversal, $reversal->transaction_type);
        $this->assertDatabaseHas('journal_entries', ['id' => $reversal->id, 'reversal_of_id' => $manual->id, 'transaction_type' => 'reversal']);
    }

    public function test_inference_maps_sources_descriptions_and_accounts_deterministically(): void
    {
        $capital = (new Capital)->getMorphClass();

        $this->assertSame(TransactionType::CapitalContribution, TransactionType::infer($capital, 'CAPITAL CONTRIBUTION - A', ['company_cash'], ['capital']));
        $this->assertSame(TransactionType::AssetCapitalContribution, TransactionType::infer($capital, 'CAPITAL CONTRIBUTION - A', ['motor_vehicles'], ['capital']));
        $this->assertSame(TransactionType::TellerCashReceipt, TransactionType::infer((new Payment)->getMorphClass(), 'TELLER CASH 123'));
        $this->assertSame(TransactionType::SuspenseAllocation, TransactionType::infer((new Payment)->getMorphClass(), 'SUSPENSE ALLOCATION 123'));
        $this->assertSame(TransactionType::SuspenseRefund, TransactionType::infer((new Payment)->getMorphClass(), 'SUSPENSE REFUND R1'));
        $this->assertSame(TransactionType::SuspenseReceipt, TransactionType::infer((new Payment)->getMorphClass(), 'SUSPENSE mpesa TX1'));
        $this->assertSame(TransactionType::LoanRepayment, TransactionType::infer((new LoanTransaction)->getMorphClass(), 'LOAN RETURN 1'));
        $this->assertSame(TransactionType::MonthEndClosing, TransactionType::infer((new AccountingPeriod)->getMorphClass(), 'MONTH END CLOSING 2026-08'));
        $this->assertSame(TransactionType::HqProfitHold, TransactionType::infer((new AccountingPeriod)->getMorphClass(), 'HQ 2% HOLD 2026-08 - X'));
        $this->assertSame(TransactionType::SavingsDeposit, TransactionType::infer((new Saving)->getMorphClass(), 'SAVING DEPOSIT', ['hq_saving'], ['savings_deposits']));
        $this->assertSame(TransactionType::SavingsWithdrawal, TransactionType::infer((new Saving)->getMorphClass(), 'SAVING TAKEN', ['savings_deposits'], ['hq_saving']));
        $this->assertSame(TransactionType::Reversal, TransactionType::infer($capital, 'CAPITAL', isReversal: true));
        $this->assertSame(TransactionType::OpeningBalance, TransactionType::infer(null, 'OPENING BALANCE - CRDB'));
        $this->assertSame(TransactionType::Manual, TransactionType::infer(null, 'BANK DEPOSIT'));
        $this->assertNull(TransactionType::infer('App\\Models\\Unknown', 'X'));
    }

    public function test_journal_exposes_and_filters_by_transaction_type(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        $ledger->openingBalance($admin->company_id, Account::Company, 500000);
        $floatId = $this->postJson('/api/v1/capital/floats', ['amount' => 1000, 'from_account' => Account::Company->value])->assertCreated()->json('data.id');
        $this->approveAsSecondUser($admin, "/api/v1/capital/floats/{$floatId}/approve");

        $today = ['from' => today()->toDateString(), 'to' => today()->toDateString()];
        $this->getJson('/api/v1/accounting/journal?'.http_build_query($today + ['transaction_type' => 'internal_transfer']))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.transaction_type', 'internal_transfer')
            ->assertJsonPath('data.0.transaction_type_label', 'Internal Transfer')
            ->assertJsonPath('data.0.can_reverse', false);
        $this->getJson('/api/v1/accounting/journal?'.http_build_query($today + ['transaction_type' => 'not_a_type']))
            ->assertUnprocessable()->assertJsonValidationErrors('transaction_type');

        $this->getJson('/api/v1/accounting/journal-transaction-types')->assertOk()
            ->assertJsonFragment(['value' => 'loan_repayment', 'label' => 'Loan Repayment']);
    }
}
