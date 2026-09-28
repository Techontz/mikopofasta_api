<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Integrations\Payments\TestPaymentWebhookConnector;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\DividendDeclaration;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanRecovery;
use App\Models\LoanTransaction;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Penalty;
use App\Models\TellerDeposit;
use App\Models\WriteOff;
use App\Services\Ledger;
use App\Services\LoanRecoveryService;
use App\Services\LoanService;
use App\Services\PaymentService;
use App\Services\PeriodClose;
use App\Services\Reports\Financial\FinancialScope;
use App\Services\Reports\Financial\ProfitLossReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\Concerns\UsesSecondApprover;
use Tests\Feature\Api\Payments\InteractsWithRepayments;
use Tests\TestCase;

/**
 * C3 Option B — recovery after write-off: confirmed money on a written-off loan is split Principal → Penalty → Interest →
 * Insurance within what was written off. The principal goes back to the HQ PRINCIPAL A/C it was lent from (no branch), while
 * every other account stays tagged to the branch that generated it (Dr PRINCIPAL A/C / Cr WRITE-OFF EXPENSE, Dr PENALTY A/C / Cr PENALTY INCOME, Dr INTEREST
 * A/C + RESERVE A/C / Cr INTEREST INCOME + INTEREST RESERVE (80/20), Dr INSURANCE A/C / Cr INSURANCE RESERVE). It never reopens
 * the loan, never changes its outstanding balance and never changes the write-off. Branch money waits for Finance.
 */
class LoanRecoveryTest extends TestCase
{
    use BuildsServiceLoans;
    use InteractsWithRepayments;
    use RefreshDatabase;
    use UsesSecondApprover;

    /** @var list<Account> */
    private const ACCOUNTS = [
        Account::Principal, Account::Interest, Account::Reserve, Account::Penalty, Account::Insurance, Account::InterestIncome,
        Account::InterestReserve, Account::RecoveryIncome, Account::PenaltyIncome, Account::InsuranceReserve, Account::LoanReceivable,
        Account::WriteOffExpense, Account::Suspense,
    ];

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-10 09:00:00'));
        $this->admin = $this->signInAdmin();
        $this->admin->company->update(['reserve_percent' => 20]);
    }

    public function test_partial_multiple_and_full_recoveries_are_split_by_component_and_leave_the_write_off_untouched(): void
    {
        $loan = $this->writtenOffLoan();
        $writeOff = WriteOff::sole();
        $this->assertEquals([134000, 100000, 3000, 30000, 1000], [$writeOff->amount, $writeOff->principal_amount, $writeOff->penalty_amount, $writeOff->interest_amount, $writeOff->insurance_amount], 'snapshot at write-off');
        $writeOffRow = $writeOff->getAttributes();
        $writeOffEntry = JournalEntry::where('description', 'WRITE-OFF '.$loan->loan_number)->sole();
        $writeOffLines = $this->entryLines($writeOffEntry->id);
        $outstanding = app(LoanService::class)->outstanding($loan);
        $schedules = $loan->schedules()->orderBy('id')->get(['amount', 'paid_amount'])->toArray();
        $before = $this->balances($this->admin, self::ACCOUNTS);
        $loans = Loan::count();

        $this->getJson("/api/v1/loans/{$loan->id}")->assertOk()
            ->assertJsonPath('data.recovery_status', LoanRecoveryService::NOT_RECOVERED)
            ->assertJsonPath('data.recovered_total', 0)
            ->assertJsonPath('data.recovery.unrecovered', 134000)
            ->assertJsonPath('data.write_off.components_status', LoanRecoveryService::COMPONENTS_SNAPSHOT)
            ->assertJsonPath('data.recovery.components.interest.written_off', 30000);

        $one = LoanRecovery::findOrFail($this->recover($loan, 50000)->assertCreated()
            ->assertJsonPath('data.recovery.status', LoanRecoveryService::PARTIALLY_RECOVERED)->json('data.recovery_id'));
        $this->assertSame([['principal', 50000.0, 0.0], ['write_off_expense', 0.0, 50000.0]], $this->entryLines($one->journal_entry_id), 'principal first: contra write-off expense');
        $entry = JournalEntry::findOrFail($one->journal_entry_id);
        $this->assertSame(TransactionType::LoanRecovery, $entry->transaction_type);
        $this->assertSame([(new LoanRecovery)->getMorphClass(), $one->id, $loan->branch_id], [$entry->source_type, $entry->source_id, $entry->branch_id]);
        $payment = Payment::findOrFail($one->payment_id);
        $this->assertSame([PaymentStatus::Allocated, 50000.0, $writeOff->id], [$payment->status, (float) $payment->allocated_amount, $one->write_off_id], 'linked to the payment and the write-off');
        $this->assertSame($one->id, PaymentAllocation::where('payment_id', $payment->id)->sole()->loan_recovery_id);

        $entries = JournalEntry::count();
        $this->recover($loan, 90000)->assertUnprocessable()->assertJsonValidationErrors(['amount' => 'Amount exceeds the unrecovered write-off balance of 84,000.']);
        $this->assertSame($entries, JournalEntry::count(), 'the ceiling rejects the excess and posts nothing');

        $two = LoanRecovery::findOrFail($this->recover($loan, 60000)->assertCreated()->json('data.recovery_id'));
        $this->assertEquals([50000, 3000, 7000, 1400, 0], [$two->principal_amount, $two->penalty_amount, $two->interest_amount, $two->reserve_amount, $two->insurance_amount]);
        $this->assertSame([
            ['principal', 50000.0, 0.0], ['write_off_expense', 0.0, 50000.0],
            ['penalty', 3000.0, 0.0], ['penalty_income', 0.0, 3000.0],
            ['interest', 5600.0, 0.0], ['reserve', 1400.0, 0.0], ['interest_income', 0.0, 5600.0], ['interest_reserve', 0.0, 1400.0],
        ], $this->entryLines($two->journal_entry_id));

        $three = LoanRecovery::findOrFail($this->recover($loan, 24000)->assertCreated()->assertJsonPath('data.recovery.status', LoanRecoveryService::FULLY_RECOVERED)->json('data.recovery_id'));
        $this->assertSame([
            ['interest', 18400.0, 0.0], ['reserve', 4600.0, 0.0], ['interest_income', 0.0, 18400.0], ['interest_reserve', 0.0, 4600.0],
            ['insurance', 1000.0, 0.0], ['insurance_reserve', 0.0, 1000.0],
        ], $this->entryLines($three->journal_entry_id), 'interest 80 / 20 and insurance last; never a fee line');
        $this->recover($loan, 1)->assertUnprocessable()->assertJsonValidationErrors(['amount' => 'This written-off loan is already fully recovered.']);

        $expected = $before;
        foreach (['principal' => 100000, 'penalty' => 3000, 'interest' => 24000, 'reserve' => 6000, 'insurance' => 1000, 'penalty_income' => 3000, 'interest_income' => 24000, 'interest_reserve' => 6000, 'insurance_reserve' => 1000, 'write_off_expense' => -100000] as $key => $delta) {
            $expected[$key] = round($before[$key] + $delta, 2);
        }
        $this->assertEquals($expected, $this->balances($this->admin, self::ACCOUNTS), 'recovery income, loan receivable and suspense never move');

        $loan->refresh();
        $this->assertSame(LoanStatus::WrittenOff, $loan->status);
        $this->assertSame($writeOffRow, $writeOff->fresh()->getAttributes());
        $this->assertSame($writeOffLines, $this->entryLines($writeOffEntry->id));
        $this->assertNull($writeOffEntry->fresh()->reversal);
        $this->assertEquals($outstanding, app(LoanService::class)->outstanding($loan), 'the loan outstanding never changes');
        $this->assertSame($schedules, $loan->schedules()->orderBy('id')->get(['amount', 'paid_amount'])->toArray());
        $this->assertSame(0, LoanTransaction::where('loan_id', $loan->id)->where('type', 'deposit')->count(), 'recoveries are not repayments');
        $this->assertSame($loans, Loan::count(), 'no loan is created');
        $this->assertEquals(0, Penalty::where('loan_id', $loan->id)->sum('paid_amount'));
        $this->assertSame(3, AuditLog::where('action', 'LoanRecovery.recorded')->count());

        $this->getJson("/api/v1/loans/{$loan->id}")->assertOk()
            ->assertJsonPath('data.loan.status', LoanStatus::WrittenOff->value)
            ->assertJsonPath('data.recovered_total', 134000)
            ->assertJsonPath('data.recovery_status', LoanRecoveryService::FULLY_RECOVERED)
            ->assertJsonPath('data.recovery.components.principal.recovered', 100000)
            ->assertJsonPath('data.recovery.components.insurance.remaining', 0)
            ->assertJsonPath('data.recoveries.0.interest', 23000)
            ->assertJsonPath('data.recoveries.0.reserve', 4600)
            ->assertJsonCount(3, 'data.recoveries');
        $this->getJson("/api/v1/loans/{$loan->id}/recoveries")->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.amount', 24000)
            ->assertJsonPath('recovery.recovered', 134000);

        $this->assertIntegrityPasses($this->admin);
    }

    public function test_period_profit_reports_and_statement_show_the_recovery_components(): void
    {
        $loan = $this->writtenOffLoan();
        $this->recover($loan, 110000)->assertCreated();

        $result = app(PeriodClose::class)->calculate($this->admin->company_id, CarbonImmutable::parse('2026-07-01'))->results->firstWhere('branch_id', $this->admin->branch_id);
        $this->assertEquals([5600, 3000, 0], [$result->interest_income, $result->penalty_income, $result->recovery_income], 'interest net of the 20% reserve, penalty income, never RECOVERED LOANS');

        $pnl = app(ProfitLossReport::class)->consolidated(new FinancialScope($this->admin->company_id, null, true, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31')));
        $this->assertEquals(7000, collect($pnl['income'])->firstWhere('key', Account::InterestIncome->value)['amount'], 'the P&L shows interest before the reserve and deducts the reserve');
        $this->assertEquals(0, $this->balance($this->admin, Account::WriteOffExpense, $this->admin->branch_id), 'the principal recovered offsets the write-off expense');

        $this->getJson('/api/v1/reports/write-off')->assertOk()
            ->assertJsonPath('data.rows.0.amount', 134000)
            ->assertJsonPath('data.rows.0.recovered_amount', 110000)
            ->assertJsonPath('data.rows.0.net_unrecovered', 24000);
        $this->getJson('/api/v1/reports/recovery')->assertOk()
            ->assertJsonPath('data.summary.written_off', 134000)
            ->assertJsonPath('data.summary.recovered_write_off', 110000)
            ->assertJsonPath('data.summary.unrecovered_write_off', 24000);
        $this->getJson("/api/v1/payments/statement/{$loan->customer_id}")->assertOk()
            ->assertJsonFragment(['type' => 'recovery', 'deposit' => 110000, 'principal' => 100000, 'penalty' => 3000, 'interest' => 7000, 'insurance' => 0, 'reserve' => 1400]);
        $this->assertIntegrityPasses($this->admin);
    }

    public function test_reversal_is_newest_first_mirrors_the_journal_and_returns_the_money_to_suspense(): void
    {
        $loan = $this->writtenOffLoan();
        $writeOffRow = WriteOff::sole()->getAttributes();
        $first = LoanRecovery::findOrFail($this->recover($loan, 40000)->json('data.recovery_id'));
        $afterFirst = $this->balances($this->admin, self::ACCOUNTS);
        $second = LoanRecovery::findOrFail($this->recover($loan, 70000)->json('data.recovery_id'));

        $this->actingAs($approver = $this->secondApprover($this->admin));
        $this->getJson("/api/v1/loans/{$loan->id}")->assertOk()
            ->assertJsonPath('data.recoveries.0.can_reverse', true)
            ->assertJsonPath('data.recoveries.1.can_reverse', false)
            ->assertJsonPath('data.recoveries.1.reverse_blocked_reason', 'A later recovery of TZS 70,000 on 2026-07-10 exists; reverse recoveries newest first.');
        $this->postJson("/api/v1/loans/{$loan->id}/recoveries/{$first->id}/reverse", ['reason' => 'DEVFLOW wrong'])->assertUnprocessable();

        $this->postJson("/api/v1/loans/{$loan->id}/recoveries/{$second->id}/reverse", ['reason' => 'DEVFLOW posted twice'])->assertOk();

        $second->refresh();
        $this->assertNotNull($second->reversed_at);
        $this->assertSame([$approver->id, 'DEVFLOW posted twice'], [$second->reversed_by, $second->reversal_reason]);
        $reversal = JournalEntry::findOrFail($second->reversal_journal_entry_id);
        $this->assertSame($second->journal_entry_id, $reversal->reversal_of_id);
        $this->assertSame([
            ['principal', 0.0, 60000.0], ['write_off_expense', 60000.0, 0.0],
            ['penalty', 0.0, 3000.0], ['penalty_income', 3000.0, 0.0],
            ['interest', 0.0, 5600.0], ['reserve', 0.0, 1400.0], ['interest_income', 5600.0, 0.0], ['interest_reserve', 1400.0, 0.0],
        ], $this->entryLines($reversal->id), 'exact mirror of the recovery journal');
        $this->assertNotNull(JournalEntry::find($second->journal_entry_id), 'the original entry is kept');

        $held = Payment::findOrFail($second->payment_id);
        $this->assertEquals([70000, 0], [$held->amount, $held->allocated_amount]);
        $this->assertSame(PaymentStatus::Unallocated, $held->status);
        $expected = $afterFirst;
        $expected['suspense'] = round($afterFirst['suspense'] + 70000, 2);
        $this->assertEquals($expected, $this->balances($this->admin, self::ACCOUNTS));
        $this->assertTrue(AuditLog::where('action', 'LoanRecovery.reversed')->where('auditable_id', $second->id)->exists());
        $this->assertSame(LoanStatus::WrittenOff, $loan->fresh()->status);
        $this->assertSame($writeOffRow, WriteOff::sole()->getAttributes(), 'the write-off is never changed');
        $this->getJson("/api/v1/loans/{$loan->id}")->assertOk()
            ->assertJsonPath('data.recovered_total', 40000)
            ->assertJsonPath('data.recovery_status', LoanRecoveryService::PARTIALLY_RECOVERED);

        $this->postJson("/api/v1/loans/{$loan->id}/recoveries/{$second->id}/reverse", ['reason' => 'again'])->assertUnprocessable()
            ->assertJsonValidationErrors(['reason' => 'This recovery has already been reversed.']);

        $this->postJson("/api/v1/payments/suspense/{$held->id}/allocate", ['loan_id' => $loan->id, 'amount' => 70000])->assertOk();
        $this->assertSame(2, LoanRecovery::where('payment_id', $held->id)->count(), 'the returned money can be re-allocated as a new recovery');
        $this->assertIntegrityPasses($this->admin);
    }

    public function test_reversal_is_blocked_when_a_fund_account_no_longer_holds_the_money(): void
    {
        $loan = $this->writtenOffLoan();
        $recovery = LoanRecovery::findOrFail($this->recover($loan, 40000)->json('data.recovery_id'));
        app(Ledger::class)->journal($this->admin->company_id, 'DEVFLOW PRINCIPAL SPENT', [
            ['account' => Account::Company, 'debit' => 30000],
            ['account' => Account::Principal, 'credit' => 30000],
        ]);

        $this->actingAs($this->secondApprover($this->admin));
        $entries = JournalEntry::count();
        $this->postJson("/api/v1/loans/{$loan->id}/recoveries/{$recovery->id}/reverse", ['reason' => 'DEVFLOW late'])->assertUnprocessable()
            ->assertJsonValidationErrors(['reason' => 'The PRINCIPAL A/C no longer holds the recovered money (available TZS 10,000, required TZS 40,000).']);
        $this->assertNull($recovery->fresh()->reversed_at);
        $this->assertSame($entries, JournalEntry::count());
    }

    public function test_a_closed_period_with_commission_calculated_or_a_dividend_blocks_the_reversal(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-20 09:00:00'));
        $loan = $this->writtenOffLoan();
        $recovery = LoanRecovery::findOrFail($this->recover($loan, 5000)->json('data.recovery_id'));

        $this->travelTo(CarbonImmutable::parse('2026-07-02 09:00:00'));
        $period = AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => AccountingPeriod::STATUS_CLOSED, 'closed_at' => now(), 'commission_calculated_at' => now()]);
        $this->actingAs($this->secondApprover($this->admin));

        $entries = JournalEntry::count();
        $this->postJson("/api/v1/loans/{$loan->id}/recoveries/{$recovery->id}/reverse", ['reason' => 'DEVFLOW late'])->assertUnprocessable()
            ->assertJsonValidationErrors(['reason' => 'Profit for the closed period 2026-06 has already been distributed (commission calculation); this recovery cannot be reversed.']);

        $period->update(['commission_calculated_at' => null]);
        DividendDeclaration::create(['company_id' => $this->admin->company_id, 'period' => '2026-06-01', 'profit_amount' => 1000, 'reinvest_percent' => 70, 'reinvest_amount' => 700, 'dividend_percent' => 30, 'dividend_amount' => 300]);
        $this->postJson("/api/v1/loans/{$loan->id}/recoveries/{$recovery->id}/reverse", ['reason' => 'DEVFLOW late'])->assertUnprocessable()
            ->assertJsonValidationErrors(['reason' => 'Profit for the closed period 2026-06 has already been distributed (dividend declaration); this recovery cannot be reversed.']);
        $this->assertNull($recovery->fresh()->reversed_at);
        $this->assertSame($entries, JournalEntry::count());
    }

    public function test_suspense_allocation_to_a_written_off_loan_is_one_recovery_per_payment_and_the_excess_stays_in_suspense(): void
    {
        $loan = $this->writtenOffLoan();
        $other = $this->writtenOffLoan();
        $payment = app(PaymentService::class)->recordUnmatched($this->admin->company_id, ['amount' => 150000, 'channel' => 'MPESA', 'transaction_id' => 'DEVFLOW-SUSP-1', 'paid_on' => today()->toDateString(), 'branch_id' => $this->admin->branch_id], $this->admin);

        $option = collect($this->getJson('/api/v1/payments/loan-options')->assertOk()->json('data'))->firstWhere('value', (string) $loan->id);
        $this->assertTrue($option['written_off']);
        $this->assertEquals([134000, 100000, 3000, 30000, 1000], [$option['outstanding']['total'], $option['outstanding']['principal'], $option['outstanding']['penalty'], $option['outstanding']['interest'], $option['outstanding']['insurance']]);

        $this->postJson("/api/v1/payments/suspense/{$payment->id}/allocate", ['loan_id' => $loan->id, 'amount' => 150000])->assertOk()
            ->assertJsonPath('message', 'Payment allocated successfully and recorded as write-off recovery (principal → penalty → interest → insurance)');

        $recovery = LoanRecovery::sole();
        $this->assertEquals([134000, 100000, 3000, 30000, 6000, 1000], [$recovery->amount, $recovery->principal_amount, $recovery->penalty_amount, $recovery->interest_amount, $recovery->reserve_amount, $recovery->insurance_amount]);
        $this->assertSame($payment->id, $recovery->payment_id);
        $payment->refresh();
        $this->assertEquals([134000, 16000], [$payment->allocated_amount, $payment->unallocated_amount]);
        $this->assertSame(PaymentStatus::Unallocated, $payment->status);
        $this->assertSame($recovery->id, PaymentAllocation::sole()->loan_recovery_id);
        $this->assertEquals(16000, $this->balance($this->admin, Account::Suspense, null));
        $this->assertSame(LoanStatus::WrittenOff, $loan->fresh()->status);

        $this->postJson("/api/v1/payments/suspense/{$payment->id}/allocate", ['loan_id' => $loan->id, 'amount' => 10000])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'Nothing is left to recover on this written-off loan.']);
        $this->postJson("/api/v1/payments/suspense/{$payment->id}/allocate", ['loan_id' => $other->id, 'amount' => 10000])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => "The payment {$payment->receipt_number} already holds a standing recovery; one payment records one recovery."]);
        $this->assertSame(1, LoanRecovery::count());

        $this->actingAs($this->secondApprover($this->admin))->postJson("/api/v1/loans/{$loan->id}/recoveries/{$recovery->id}/reverse", ['reason' => 'DEVFLOW wrong customer'])->assertOk();
        $payment->refresh();
        $this->assertEquals([0, 150000], [$payment->allocated_amount, $payment->unallocated_amount]);
        $this->assertNotNull(PaymentAllocation::sole()->reversed_at);
        $this->assertEquals(150000, $this->balance($this->admin, Account::Suspense, null));
        $this->assertIntegrityPasses($this->admin);
    }

    public function test_webhook_reference_of_a_written_off_loan_records_one_recovery_and_holds_the_excess(): void
    {
        $loan = $this->writtenOffLoan();
        $payload = ['reference' => $loan->loan_number, 'amount' => 140000, 'phone' => '255700000009', 'channel' => 'VODACOM', 'transaction_id' => 'DEVFLOW-WH-1'];

        $this->webhook($payload)->assertOk()->assertJsonPath('status', 'PAYMENT_SUCCESS');
        $this->webhook($payload)->assertOk()->assertJsonPath('status', 'DUPLICATE');

        $recovery = LoanRecovery::sole();
        $this->assertEquals([134000, 100000, 3000, 30000, 1000], [$recovery->amount, $recovery->principal_amount, $recovery->penalty_amount, $recovery->interest_amount, $recovery->insurance_amount]);
        $this->assertSame(1, JournalEntry::where('transaction_type', TransactionType::LoanRecovery->value)->count());
        $this->assertEquals(6000, Payment::whereNotNull('parent_id')->sole()->amount, 'the excess is held as suspense credit');
        $this->assertSame(LoanRecoveryService::FULLY_RECOVERED, app(LoanRecoveryService::class)->position($loan)['status']);
        $this->assertSame(LoanStatus::WrittenOff, $loan->fresh()->status);
        $this->assertIntegrityPasses($this->admin);
    }

    public function test_an_idempotent_replay_and_a_duplicate_transaction_id_record_one_recovery(): void
    {
        $loan = $this->writtenOffLoan();
        $headers = ['Idempotency-Key' => 'recovery-key-0001'];

        $this->postJson("/api/v1/loans/{$loan->id}/recoveries", ['amount' => 1000, 'method' => 'CASH'], $headers)->assertCreated();
        $this->postJson("/api/v1/loans/{$loan->id}/recoveries", ['amount' => 1000, 'method' => 'CASH'], $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->postJson("/api/v1/loans/{$loan->id}/recoveries", ['amount' => 2000, 'method' => 'BANK', 'transaction_id' => 'DEVFLOW-BANK-1'])->assertCreated();
        $this->postJson("/api/v1/loans/{$loan->id}/recoveries", ['amount' => 2000, 'method' => 'BANK', 'transaction_id' => 'DEVFLOW-BANK-1'])->assertUnprocessable()->assertJsonValidationErrors('transaction_id');

        $this->assertSame(2, LoanRecovery::count());
        $this->assertSame(2, JournalEntry::where('transaction_type', TransactionType::LoanRecovery->value)->count());
    }

    public function test_branch_money_for_a_written_off_loan_is_recovered_only_when_finance_confirms_it(): void
    {
        $loan = $this->writtenOffLoan();
        $teller = $this->employeeWithRole($this->admin, 'teller');
        $finance = $this->employeeWithRole($this->admin, 'finance');
        $before = $this->balances($this->admin, [...self::ACCOUNTS]);
        $recoveryEntries = fn (): int => JournalEntry::where('transaction_type', TransactionType::LoanRecovery->value)->count();

        $this->actingAs($teller)->postJson("/api/v1/loans/{$loan->id}/recoveries", ['amount' => 30000, 'method' => 'CASH'])->assertStatus(202)
            ->assertJsonPath('data.status', PaymentStatus::PendingVerification->value);
        $this->actingAs($teller)->postJson("/api/v1/loans/{$loan->id}/recoveries", ['amount' => 1000, 'method' => 'MOBILE'])->assertUnprocessable()->assertJsonValidationErrors('transaction_id');
        $entries = JournalEntry::count();
        $mobile = Payment::findOrFail($this->actingAs($teller)->postJson("/api/v1/loans/{$loan->id}/recoveries", ['amount' => 20000, 'method' => 'VODACOM', 'transaction_id' => 'DEVFLOW-MP-1'])->assertStatus(202)
            ->assertJsonPath('data.status', PaymentStatus::PendingApproval->value)->json('data.payment_id'));
        $this->assertSame($entries, JournalEntry::count(), 'a pending non-cash receipt posts nothing');
        $this->actingAs($teller)->postJson("/api/v1/loans/{$loan->id}/recoveries", ['amount' => 90000, 'method' => 'CASH'])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'Amount exceeds the unrecovered write-off balance still receivable of 84,000.']);

        $this->assertSame(0, LoanRecovery::count());
        $this->assertSame(0, $recoveryEntries());
        $expected = $before;
        $expected['suspense'] = round($before['suspense'] + 30000, 2);
        $this->assertEquals($expected, $this->balances($this->admin, self::ACCOUNTS), 'pending branch money changes no fund, income, reserve or write-off expense');
        $this->actingAs($this->admin)->getJson("/api/v1/loans/{$loan->id}")->assertOk()->assertJsonPath('data.recovery.pending', 50000)->assertJsonPath('data.recovered_total', 0);

        $this->actingAs($teller)->postJson("/api/v1/payments/branch-receipts/{$mobile->id}/approve")->assertForbidden();
        $this->actingAs($finance)->postJson("/api/v1/payments/branch-receipts/{$mobile->id}/approve")->assertOk();
        $this->assertEquals([20000, 20000], [LoanRecovery::sole()->amount, LoanRecovery::sole()->principal_amount]);
        $this->assertSame([PaymentStatus::Allocated, $mobile->id], [$mobile->fresh()->status, LoanRecovery::sole()->payment_id]);

        $cash = Payment::where('status', PaymentStatus::PendingVerification->value)->sole();
        $bank = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'NMB']);
        $this->actingAs($teller)->postJson('/api/v1/teller/bank-deposits', ['bank_account_id' => $bank->id, 'slip_number' => 'SLIP-REC', 'amount' => 30000, 'deposit_date' => today()->toDateString(), 'payment_ids' => [$cash->id]])->assertCreated();
        $deposit = TellerDeposit::sole();
        $this->actingAs($finance)->postJson("/api/v1/payments/reconciliation/{$deposit->id}/verify", ['statement_amount' => 30000, 'statement_reference' => 'NMB-1'])->assertOk();
        $this->actingAs($finance)->postJson("/api/v1/payments/reconciliation/{$deposit->id}/confirm")->assertUnprocessable();

        $this->assertSame(2, LoanRecovery::count());
        $this->assertEquals(50000, LoanRecovery::sum('principal_amount'));
        $this->assertSame(2, $recoveryEntries());
        $this->assertSame(PaymentStatus::Confirmed, $cash->fresh()->status);
        $this->actingAs($this->admin);
        $this->assertIntegrityPasses($this->admin);
    }

    public function test_permissions_company_scope_and_loan_status(): void
    {
        $loan = $this->writtenOffLoan();
        $active = $this->serviceLoan($this->admin);

        $this->recover($active, 1000)->assertUnprocessable()->assertJsonValidationErrors(['amount' => 'Recoveries can only be recorded on a written-off loan.']);
        $this->postJson('/api/v1/penalties/'.Penalty::where('loan_id', $loan->id)->value('id').'/pay', ['penart_paid' => 100])->assertUnprocessable()
            ->assertJsonValidationErrors(['penart_paid' => 'The loan has been written off; record the money as a recovery on the loan instead.']);

        $officer = $this->employeeWithRole($this->admin, 'loan_officer');
        $this->actingAs($officer)->postJson("/api/v1/loans/{$loan->id}/recoveries", ['amount' => 1000, 'method' => 'CASH'])->assertForbidden();
        $teller = $this->employeeWithRole($this->admin, 'teller');
        $this->actingAs($teller)->postJson("/api/v1/loans/{$loan->id}/recoveries", ['amount' => 1000, 'method' => 'CASH'])->assertStatus(202);
        $this->assertSame(0, LoanRecovery::count(), 'a teller never posts a recovery directly');
        $recoveryId = $this->actingAs($this->employeeWithRole($this->admin, 'finance'))->postJson("/api/v1/loans/{$loan->id}/recoveries", ['amount' => 1000, 'method' => 'BANK', 'reference' => 'DEVFLOW-BANK'])->assertCreated()->json('data.recovery_id');
        $this->actingAs($teller)->postJson("/api/v1/loans/{$loan->id}/recoveries/{$recoveryId}/reverse", ['reason' => 'nope'])->assertForbidden();

        $outsider = $this->signInAdmin();
        $this->actingAs($outsider)->postJson("/api/v1/loans/{$loan->id}/recoveries", ['amount' => 1000, 'method' => 'CASH'])->assertNotFound();
        $this->actingAs($outsider)->getJson("/api/v1/loans/{$loan->id}/recoveries")->assertNotFound();
        $this->actingAs($outsider)->postJson("/api/v1/loans/{$loan->id}/recoveries/{$recoveryId}/reverse", ['reason' => 'nope'])->assertNotFound();
        $this->assertSame(1, LoanRecovery::count());
    }

    public function test_a_legacy_write_off_without_a_reliable_split_is_ambiguous_and_takes_no_recovery(): void
    {
        $loan = $this->writtenOffLoan();
        WriteOff::sole()->update(['principal_amount' => null, 'penalty_amount' => null, 'interest_amount' => null, 'insurance_amount' => null]);
        $teller = $this->employeeWithRole($this->admin, 'teller');

        $this->getJson("/api/v1/loans/{$loan->id}")->assertOk()
            ->assertJsonPath('data.write_off.components_status', LoanRecoveryService::COMPONENTS_AMBIGUOUS)
            ->assertJsonPath('data.recovery.components', null)
            ->assertJsonPath('data.recovery.ambiguous_reason', 'The write-off predates component tracking and does not record the principal written off.');

        $this->recover($loan, 1000)->assertUnprocessable()->assertJsonValidationErrors(['amount' => LoanRecoveryService::AMBIGUOUS_MESSAGE]);
        $this->actingAs($teller)->postJson("/api/v1/loans/{$loan->id}/recoveries", ['amount' => 1000, 'method' => 'CASH'])->assertUnprocessable()->assertJsonValidationErrors(['amount' => LoanRecoveryService::AMBIGUOUS_MESSAGE]);
        $this->actingAs($teller)->postJson("/api/v1/teller/customers/{$loan->customer_id}/deposit", ['depost' => 1000, 'p_method' => 'CASH'])->assertUnprocessable()->assertJsonValidationErrors(['depost' => LoanRecoveryService::AMBIGUOUS_MESSAGE]);
        $this->actingAs($this->admin);
        $payment = app(PaymentService::class)->recordUnmatched($this->admin->company_id, ['amount' => 5000, 'channel' => 'MPESA', 'transaction_id' => 'DEVFLOW-AMB-1', 'paid_on' => today()->toDateString(), 'branch_id' => $this->admin->branch_id], $this->admin);
        $this->postJson("/api/v1/payments/suspense/{$payment->id}/allocate", ['loan_id' => $loan->id, 'amount' => 5000])->assertUnprocessable()->assertJsonValidationErrors(['amount' => LoanRecoveryService::AMBIGUOUS_MESSAGE]);
        $this->assertNull(collect($this->getJson('/api/v1/payments/loan-options')->json('data'))->firstWhere('value', (string) $loan->id));

        $this->webhook(['reference' => $loan->loan_number, 'amount' => 7000, 'phone' => '255700000009', 'channel' => 'VODACOM', 'transaction_id' => 'DEVFLOW-WH-AMB'])->assertOk();
        $this->assertEquals(7000, Payment::whereNotNull('parent_id')->sole()->amount, 'provider money stays in suspense');
        $this->assertSame(0, LoanRecovery::count());
        $this->assertIntegrityPasses($this->admin);
    }

    public function test_a_legacy_write_off_split_is_derived_from_loan_data_and_legacy_interest_only_recoveries_count_as_interest(): void
    {
        $loan = $this->writtenOffLoan();
        $writeOff = WriteOff::sole();
        $writeOff->update(['penalty_amount' => null, 'interest_amount' => null, 'insurance_amount' => null]);
        $legacy = LoanRecovery::create(['company_id' => $loan->company_id, 'branch_id' => $loan->branch_id, 'loan_id' => $loan->id, 'write_off_id' => $writeOff->id, 'customer_id' => $loan->customer_id, 'amount' => 25000, 'method' => 'CASH', 'recovered_on' => today()->toDateString()]);
        $entry = app(Ledger::class)->journal($loan->company_id, 'LOAN RECOVERY '.$loan->loan_number, [
            ['account' => Account::Interest, 'branch' => $loan->branch_id, 'debit' => 25000],
            ['account' => Account::InterestIncome, 'branch' => $loan->branch_id, 'credit' => 25000],
        ], $legacy, null, $loan->branch_id, $this->admin, TransactionType::LoanRecovery);
        $legacy->forceFill(['journal_entry_id' => $entry->id])->save();

        $position = app(LoanRecoveryService::class)->position($loan);
        $this->assertSame(LoanRecoveryService::COMPONENTS_DERIVED, $position['components_status']);
        $this->assertEquals([100000, 3000, 5000, 1000, 109000], [$position['components']['principal']['remaining'], $position['components']['penalty']['remaining'], $position['components']['interest']['remaining'], $position['components']['insurance']['remaining'], $position['unrecovered']]);
        $this->assertIntegrityPasses($this->admin);

        $recovery = LoanRecovery::findOrFail($this->recover($loan, 104000)->assertCreated()->json('data.recovery_id'));
        $this->assertEquals([100000, 3000, 1000, 200, 0], [$recovery->principal_amount, $recovery->penalty_amount, $recovery->interest_amount, $recovery->reserve_amount, $recovery->insurance_amount]);
        $this->recover($loan, 6000)->assertUnprocessable()->assertJsonValidationErrors(['amount' => 'Amount exceeds the unrecovered write-off balance of 5,000.']);
        $this->recover($loan, 5000)->assertCreated()->assertJsonPath('data.recovery.status', LoanRecoveryService::FULLY_RECOVERED);
        $this->assertTrue($legacy->fresh()->isLegacy(), 'the legacy row stays as booked');
        $this->assertIntegrityPasses($this->admin);
    }

    public function test_a_ledger_failure_rolls_the_recovery_back(): void
    {
        $loan = $this->writtenOffLoan();
        $entries = JournalEntry::count();
        $real = new Ledger;
        $this->app->instance(Ledger::class, Mockery::mock(Ledger::class, function ($mock) use ($real): void {
            $mock->shouldReceive('balance')->andReturnUsing(fn (...$arguments) => $real->balance(...$arguments));
            $mock->shouldReceive('journal')->andReturnUsing(fn (...$arguments) => str_starts_with($arguments[1], 'LOAN RECOVERY') ? throw new RuntimeException('Ledger unavailable') : $real->journal(...$arguments));
        }));
        $this->app->forgetInstance(LoanRecoveryService::class);

        try {
            app(LoanRecoveryService::class)->record($loan, 5000, CarbonImmutable::today(), 'CASH', null, $this->admin);
            $this->fail('The ledger failure should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Ledger unavailable', $exception->getMessage());
        }

        $this->assertSame(0, LoanRecovery::count());
        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame(0, AuditLog::where('action', 'LoanRecovery.recorded')->count());
    }

    /**
     * A service loan (100,000 principal, 30,000 interest, 1,000 insurance, fee deducted) with an unpaid 3,000 cash-basis penalty,
     * written off: WriteOff.amount = 134,000 (snapshot principal 100,000 / penalty 3,000 / interest 30,000 / insurance 1,000).
     */
    private function writtenOffLoan(): Loan
    {
        $loan = $this->serviceLoan($this->admin);
        app(LoanService::class)->chargePenalty($loan, 3000, CarbonImmutable::today()->subDays(2));
        app(LoanService::class)->writeOff($loan->fresh(), $this->admin);

        return $loan->fresh();
    }

    private function recover(Loan $loan, float $amount): TestResponse
    {
        return $this->postJson("/api/v1/loans/{$loan->id}/recoveries", ['amount' => $amount, 'method' => 'CASH', 'reference' => 'DEVFLOW-REC']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function webhook(array $payload): TestResponse
    {
        $body = json_encode($payload);
        $user = auth()->user();
        auth()->forgetGuards();

        $response = $this->call('POST', '/api/webhooks/payments', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, TestPaymentWebhookConnector::DEFAULT_SECRET),
        ], $body);

        auth()->forgetGuards();
        if ($user !== null) {
            $this->actingAs($user);
        }

        return $response;
    }
}
