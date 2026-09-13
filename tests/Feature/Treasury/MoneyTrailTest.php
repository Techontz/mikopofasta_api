<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Enums\ActiveStatus;
use App\Http\Middleware\EnsureIdempotency;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\CapitalContribution;
use App\Models\ChartOfAccount;
use App\Models\DisbursementBatch;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\Shareholder;
use Illuminate\Database\QueryException;

/**
 * The money trail, end to end:
 *
 *   Shareholder → Capital Contribution → Company account → Transfer →
 *   Loan Disbursement → Customer / Loan
 *
 * Every step is a real posting through the real endpoints. No balance is set
 * by hand anywhere in this file. See docs/modules/capital.md, "Money trail".
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

function trailShareholder(string $name, string $email, string $phone): Shareholder
{
    officerAt('Head Office', RoleName::Finance);

    $id = test()->postJson('/api/v1/shareholders', [
        'fullName' => $name,
        'phone' => $phone,
        'email' => $email,
        'gender' => 'female',
        'dateOfBirth' => '1985-05-05',
    ])->assertCreated()->json('data.id');

    return Shareholder::query()->findOrFail($id);
}

/** CRDB (8000) — where capital lands. NMB (8010) — the operational account. */
function trailAccount(string $code): BankAccount
{
    return BankAccount::query()
        ->whereHas('chartAccount', fn ($q) => $q->where('code', $code))
        ->firstOrFail();
}

function trailBalance(string $code): float
{
    return (float) ChartOfAccount::query()->where('code', $code)->firstOrFail()
        ->load('balances')->cachedBalance()->toDecimalString();
}

function trailSystemBalance(SystemAccountCode $code): float
{
    return (float) app(AccountResolver::class)->system($code)->load('balances')->cachedBalance()->toDecimalString();
}

/** @param array<string, mixed> $overrides */
function trailContribute(Shareholder $shareholder, string $amount, array $overrides = []): Illuminate\Testing\TestResponse
{
    officerAt('Head Office', RoleName::Finance);

    return test()->postJson('/api/v1/capital-contributions', array_merge([
        'shareholderId' => $shareholder->id,
        'amount' => $amount,
        'payMethod' => 'bank_transfer',
        'bankAccountId' => trailAccount('8000')->id,
        'sourceAccountName' => 'Personal CRDB',
        'sourceAccountNumber' => '0150999888777',
    ], $overrides));
}

/** A loan waiting on Finance, with NO money put in the books for it. */
function trailUnfundedLoanAtFinance(): Loan
{
    $loan = loanAtCreditReview();
    officerAt('Kakonko', RoleName::CreditOfficer);
    test()->postJson("/api/v1/loans/{$loan->id}/telco-verify", ['passed' => true])->assertOk();

    return $loan->refresh();
}

/** @param array<string, mixed> $payload */
function trailPrepare(Loan $loan, array $payload = []): Illuminate\Testing\TestResponse
{
    officerAt('Head Office', RoleName::Finance);

    return test()->postJson(
        "/api/v1/loans/{$loan->id}/prepare-disbursement",
        array_merge(['channel' => 'bank', 'fundingBankAccountId' => trailAccount('8010')->id], $payload),
    );
}

function trailSettle(Loan $loan): Illuminate\Testing\TestResponse
{
    officerAt('Head Office', RoleName::Finance);

    return test()->postJson("/api/v1/loans/{$loan->id}/settle-disbursement", ['success' => true]);
}

/** Capital into 8000, then moved to the operational account 8010. */
function trailFundOperationalAccount(string $amount = '50000000'): void
{
    $shareholder = trailShareholder('Operating Capital', 'ops@example.test', '0711000111');
    trailContribute($shareholder, $amount)->assertCreated();

    officerAt('Head Office', RoleName::Finance);
    test()->postJson('/api/v1/bank-transfers', [
        'kind' => 'salary_advance',
        'fromAccountId' => trailAccount('8000')->id,
        'toAccountId' => trailAccount('8010')->id,
        'amount' => $amount,
        'chargeFee' => '0',
        'reason' => 'Capital to operations',
    ])->assertCreated();
}

describe('shareholder capital contributions', function (): void {
    it('records a contribution as a permanent, traceable transaction', function (): void {
        $shareholder = trailShareholder('Asha Juma', 'asha@example.test', '0713000001');

        $response = trailContribute($shareholder, '10000000')
            ->assertCreated()
            ->assertJsonPath('data.shareholderId', (string) $shareholder->id)
            ->assertJsonPath('data.amount', '10000000.00')
            ->assertJsonPath('data.sourceAccountName', 'Personal CRDB')
            ->assertJsonPath('data.sourceAccountNumber', '0150999888777')
            ->assertJsonPath('data.receivedAccountCode', '8000');

        $contribution = CapitalContribution::query()->sole();

        // Reference, date/time, recorder, company account, source account.
        expect($contribution->reference)->toBe('CAP-'.str_pad((string) $contribution->id, 7, '0', STR_PAD_LEFT))
            ->and($response->json('data.reference'))->toBe($contribution->reference)
            ->and($contribution->created_at)->not->toBeNull()
            ->and($contribution->created_by)->not->toBeNull()
            ->and($contribution->bank_account_id)->toBe(trailAccount('8000')->id)
            ->and($contribution->received_account_id)->toBe(trailAccount('8000')->chart_account_id);

        // The entry points back at the contribution, and posts Dr bank / Cr Capital.
        $entry = JournalEntry::query()->with('lines')->findOrFail($contribution->journal_entry_id);
        expect($entry->source_type)->toBe(JournalSourceType::CapitalInjection)
            ->and($entry->source_id)->toBe($contribution->id)
            ->and($entry->isBalanced())->toBeTrue();

        $capitalId = app(AccountResolver::class)->systemId(SystemAccountCode::Capital);
        expect((float) $entry->lines->firstWhere('account_id', $contribution->received_account_id)->debit_amount)->toBe(10000000.0)
            ->and((float) $entry->lines->firstWhere('account_id', $capitalId)->credit_amount)->toBe(10000000.0);

        // The list the Add Capitals screen reads carries the same trail.
        $this->getJson('/api/v1/capital-contributions')
            ->assertOk()
            ->assertJsonPath('data.0.reference', $contribution->reference)
            ->assertJsonPath('data.0.receivedAccountCode', '8000')
            ->assertJsonPath('data.0.journalEntryNumber', $entry->entry_number);

        expect(AuditLog::query()->where('auditable_type', $contribution->getMorphClass())
            ->where('auditable_id', $contribution->id)->exists())->toBeTrue();
    });

    it('increases total contributed capital with every contribution', function (): void {
        $shareholder = trailShareholder('Asha Juma', 'asha@example.test', '0713000001');
        $capitalBefore = trailSystemBalance(SystemAccountCode::Capital);

        trailContribute($shareholder, '2500000')->assertCreated();
        trailContribute($shareholder, '4000000.50')->assertCreated();
        trailContribute($shareholder, '1000000', ['payMethod' => 'cash', 'bankAccountId' => null])->assertCreated();

        $this->getJson('/api/v1/shareholders')
            ->assertOk()
            ->assertJsonPath('data.0.totalContributed', '7500000.50')
            ->assertJsonPath('data.0.ownershipPercentage', '100.00')
            ->assertJsonPath('meta.totalContributed', '7500000.50');

        expect(trailSystemBalance(SystemAccountCode::Capital) - $capitalBefore)->toBe(7500000.5);
        expect(CapitalContribution::query()->count())->toBe(3);
    });

    it('lists a shareholder\'s full capital history, removed entries included', function (): void {
        $shareholder = trailShareholder('Asha Juma', 'asha@example.test', '0713000001');
        trailContribute($shareholder, '3000000')->assertCreated();
        $removed = trailContribute($shareholder, '1000000')->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/capital-contributions/{$removed}")->assertOk();

        $response = $this->getJson("/api/v1/shareholders/{$shareholder->id}")
            ->assertOk()
            ->assertJsonPath('data.totalContributed', '3000000.00')
            ->assertJsonCount(2, 'meta.contributions');

        expect($response->json('meta.contributions.1.removedAt'))->not->toBeNull()
            ->and($response->json('meta.contributions.0.journalEntryNumber'))->toStartWith('JE-');
    });
});

describe('ownership percentage', function (): void {
    it('is cumulative contributions over all contributions', function (): void {
        $a = trailShareholder('Shareholder A', 'a@example.test', '0713000001');
        $b = trailShareholder('Shareholder B', 'b@example.test', '0713000002');

        trailContribute($a, '6000000')->assertCreated();
        trailContribute($b, '5000000')->assertCreated();
        trailContribute($a, '4000000')->assertCreated();

        $rows = collect($this->getJson('/api/v1/shareholders')->assertOk()->json('data'))->keyBy('fullName');

        expect($rows['Shareholder A']['totalContributed'])->toBe('10000000.00')
            ->and($rows['Shareholder A']['ownershipPercentage'])->toBe('66.67')
            ->and($rows['Shareholder B']['totalContributed'])->toBe('5000000.00')
            ->and($rows['Shareholder B']['ownershipPercentage'])->toBe('33.33');
    });

    it('does not change when the company spends money', function (): void {
        $a = trailShareholder('Shareholder A', 'a@example.test', '0713000001');
        $b = trailShareholder('Shareholder B', 'b@example.test', '0713000002');
        trailContribute($a, '10000000')->assertCreated();
        trailContribute($b, '5000000')->assertCreated();

        $shares = fn (): array => collect($this->getJson('/api/v1/shareholders')->json('data'))
            ->mapWithKeys(fn (array $r): array => [$r['fullName'] => $r['ownershipPercentage']])->all();
        $before = $shares();
        $bankBefore = trailBalance('8000');

        // Spend: move money out to operations with a bank charge (an expense),
        // then lend it to a customer.
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/bank-transfers', [
            'kind' => 'salary_advance',
            'fromAccountId' => trailAccount('8000')->id,
            'toAccountId' => trailAccount('8010')->id,
            'amount' => '8000000',
            'chargeFee' => '15000',
            'reason' => 'Operations',
        ])->assertCreated();

        $loan = trailUnfundedLoanAtFinance();
        trailPrepare($loan)->assertCreated();
        trailSettle($loan)->assertOk();

        expect(trailBalance('8000'))->toBeLessThan($bankBefore)
            ->and($shares())->toBe($before)
            ->and($before)->toBe(['Shareholder A' => '66.67', 'Shareholder B' => '33.33']);
    });
});

describe('capital to operational bank/cash', function (): void {
    it('is an internal transfer, not new capital', function (): void {
        $shareholder = trailShareholder('Asha Juma', 'asha@example.test', '0713000001');
        trailContribute($shareholder, '20000000')->assertCreated();

        $capitalBefore = trailSystemBalance(SystemAccountCode::Capital);
        $injectionsBefore = JournalEntry::query()->where('source_type', JournalSourceType::CapitalInjection)->count();
        $from = trailBalance('8000');
        $to = trailBalance('8010');

        officerAt('Head Office', RoleName::Finance);
        $reference = $this->postJson('/api/v1/bank-transfers', [
            'kind' => 'salary_advance',
            'fromAccountId' => trailAccount('8000')->id,
            'toAccountId' => trailAccount('8010')->id,
            'amount' => '12000000',
            'chargeFee' => '0',
            'reason' => 'Capital to operations',
        ])->assertCreated()->json('data.reference');

        expect($reference)->not->toBeEmpty();

        $entry = JournalEntry::query()->with('lines')->latest('id')->firstOrFail();
        expect($entry->source_type)->toBe(JournalSourceType::Transfer)
            ->and($entry->isBalanced())->toBeTrue();

        // Money moved; nothing was created, and nothing is counted twice.
        expect(trailBalance('8000') - $from)->toBe(-12000000.0)
            ->and(trailBalance('8010') - $to)->toBe(12000000.0)
            ->and(trailSystemBalance(SystemAccountCode::Capital))->toBe($capitalBefore)
            ->and(JournalEntry::query()->where('source_type', JournalSourceType::CapitalInjection)->count())->toBe($injectionsBefore)
            ->and(CapitalContribution::query()->sum('amount'))->toEqual('20000000.00');
    });
});

describe('loan disbursement', function (): void {
    it('credits the chosen bank account and debits the customer\'s loan receivable', function (): void {
        trailFundOperationalAccount();
        $loan = trailUnfundedLoanAtFinance();
        $bankBefore = trailBalance('8010');
        $receivableBefore = trailSystemBalance(SystemAccountCode::LoanReceivable);

        trailPrepare($loan)->assertCreated()->assertJsonPath('data.fundingAccountCode', '8010');
        trailSettle($loan)->assertOk()->assertJsonPath('data.status', 'active');

        $loan->refresh();
        $net = (float) $loan->principal_amount - (float) $loan->fee_charged;

        expect(trailBalance('8010') - $bankBefore)->toBe(-$net)
            ->and(trailSystemBalance(SystemAccountCode::LoanReceivable) - $receivableBefore)->toBe((float) $loan->principal_amount);

        // No equity is created by lending: Principal is not touched.
        $entry = JournalEntry::query()->with('lines')
            ->where('source_type', JournalSourceType::LoanDisbursement)->where('source_id', $loan->id)->sole();
        $principalId = app(AccountResolver::class)->systemId(SystemAccountCode::Principal);
        expect($entry->lines->firstWhere('account_id', $principalId))->toBeNull()
            ->and($entry->isBalanced())->toBeTrue();
    });

    it('links the transaction to the customer, the loan and the batch', function (): void {
        trailFundOperationalAccount();
        $loan = trailUnfundedLoanAtFinance();
        trailPrepare($loan)->assertCreated();
        trailSettle($loan)->assertOk();

        $batch = DisbursementBatch::query()->where('loan_id', $loan->id)->sole();
        $entry = JournalEntry::query()->with('lines')->findOrFail($batch->journal_entry_id);

        expect($batch->settled_loan_id)->toBe($loan->id)
            ->and($batch->funding_account_id)->toBe(trailAccount('8010')->chart_account_id)
            ->and($entry->source_type)->toBe(JournalSourceType::LoanDisbursement)
            ->and($entry->source_id)->toBe($loan->id);

        foreach ($entry->lines as $line) {
            expect($line->loan_id)->toBe($loan->id)->and($line->customer_id)->toBe($loan->customer_id);
        }

        $this->getJson("/api/v1/loans/{$loan->id}/disbursements")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'success')
            ->assertJsonPath('data.0.journalEntryNumber', $entry->entry_number)
            ->assertJsonPath('data.0.fundingAccountCode', '8010');
    });

    it('can pay out from branch cash', function (): void {
        $loan = trailUnfundedLoanAtFinance();

        // The Kakonko till is empty, so it cannot fund the payout.
        trailPrepare($loan, ['fundingSource' => 'cash', 'fundingBankAccountId' => null])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');

        expect($loan->fresh()->status->value)->toBe('pending_finance')
            ->and(DisbursementBatch::query()->where('loan_id', $loan->id)->exists())->toBeFalse();
    });

    it('refuses to prepare a payout the account cannot cover', function (): void {
        $loan = trailUnfundedLoanAtFinance();

        trailPrepare($loan)->assertUnprocessable();

        expect($loan->fresh()->status->value)->toBe('pending_finance')
            ->and(DisbursementBatch::query()->where('loan_id', $loan->id)->exists())->toBeFalse();
    });

    it('does not promise the same money to two loans', function (): void {
        $first = trailUnfundedLoanAtFinance();
        $second = trailUnfundedLoanAtFinance();

        // Exactly enough for one payout.
        $net = app(App\Domain\Loans\Services\DisbursementFunding::class)->netPayout($first);
        trailFundOperationalAccount($net->toDecimalString());

        trailPrepare($first)->assertCreated();
        trailPrepare($second)->assertUnprocessable();
    });
});

describe('duplicates and failures', function (): void {
    it('never disburses the same loan twice', function (): void {
        trailFundOperationalAccount();
        $loan = trailUnfundedLoanAtFinance();
        trailPrepare($loan)->assertCreated();

        trailSettle($loan)->assertOk();
        trailSettle($loan)->assertStatus(409);

        expect(JournalEntry::query()->where('source_type', JournalSourceType::LoanDisbursement)
            ->where('source_id', $loan->id)->count())->toBe(1);

        // And the database refuses a second successful batch outright.
        $batch = DisbursementBatch::query()->where('loan_id', $loan->id)->sole();
        expect(fn () => DisbursementBatch::query()->create([
            'loan_id' => $loan->id,
            'batch_reference' => 'DUPLICATE-1',
            'attempt_number' => 2,
            'channel' => $batch->channel,
            'status' => 'success',
            'requested_by' => $batch->requested_by,
            'requested_at' => now(),
            'settled_loan_id' => $loan->id,
        ]))->toThrow(QueryException::class);
    });

    it('refuses a contribution reference that was already recorded', function (): void {
        $shareholder = trailShareholder('Asha Juma', 'asha@example.test', '0713000001');

        trailContribute($shareholder, '1000000', ['reference' => 'MPESA-QX12345'])->assertCreated();
        trailContribute($shareholder, '1000000', ['reference' => 'MPESA-QX12345'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference');

        expect(CapitalContribution::query()->count())->toBe(1);
    });

    it('records a resubmitted contribution only once', function (): void {
        $shareholder = trailShareholder('Asha Juma', 'asha@example.test', '0713000001');
        officerAt('Head Office', RoleName::Finance);

        $payload = [
            'shareholderId' => $shareholder->id,
            'amount' => '1000000',
            'payMethod' => 'cash',
        ];

        $first = $this->postJson('/api/v1/capital-contributions', $payload, [EnsureIdempotency::HEADER => 'cap-key-1']);
        $second = $this->postJson('/api/v1/capital-contributions', $payload, [EnsureIdempotency::HEADER => 'cap-key-1']);

        $first->assertCreated();
        expect($second->json('data.id'))->toBe($first->json('data.id'))
            ->and(CapitalContribution::query()->count())->toBe(1)
            ->and(JournalEntry::query()->where('source_type', JournalSourceType::CapitalInjection)->count())->toBe(1);
    });

    it('rolls a failed disbursement posting back completely', function (): void {
        trailFundOperationalAccount();
        $loan = trailUnfundedLoanAtFinance();
        trailPrepare($loan)->assertCreated();

        $entriesBefore = JournalEntry::query()->count();
        $auditBefore = AuditLog::query()->count();

        // The funding account is closed in the ledger between preparation and
        // settlement, so LedgerService refuses the posting.
        ChartOfAccount::query()->whereKey(trailAccount('8010')->chart_account_id)
            ->update(['status' => ActiveStatus::Inactive]);

        // LedgerService treats a refused posting as a server fault (500,
        // UNBALANCED_JOURNAL_ENTRY) — what matters is that nothing commits.
        trailSettle($loan)->assertStatus(500)->assertJsonPath('error_code', 'UNBALANCED_JOURNAL_ENTRY');

        $batch = DisbursementBatch::query()->where('loan_id', $loan->id)->sole();

        expect($loan->fresh()->status->value)->toBe('awaiting_disbursement')
            ->and($loan->fresh()->disbursement_date)->toBeNull()
            ->and($batch->status->value)->toBe('pending')
            ->and($batch->journal_entry_id)->toBeNull()
            ->and($batch->settled_loan_id)->toBeNull()
            ->and(JournalEntry::query()->count())->toBe($entriesBefore)
            ->and(AuditLog::query()->count())->toBe($auditBefore);
    });

    it('rolls a failed contribution posting back completely', function (): void {
        $shareholder = trailShareholder('Asha Juma', 'asha@example.test', '0713000001');
        $entriesBefore = JournalEntry::query()->count();

        app(AccountResolver::class)->system(SystemAccountCode::Capital)
            ->update(['status' => ActiveStatus::Inactive]);

        trailContribute($shareholder, '1000000')->assertStatus(500)->assertJsonPath('error_code', 'UNBALANCED_JOURNAL_ENTRY');

        expect(CapitalContribution::withTrashed()->count())->toBe(0)
            ->and(JournalEntry::query()->count())->toBe($entriesBefore);
    });
});

describe('full traceability', function (): void {
    it('follows the money from shareholder to customer loan', function (): void {
        // 1. Shareholder.
        $shareholder = trailShareholder('Asha Juma', 'asha@example.test', '0713000001');

        // 2–3. Contribution into company capital, landing in CRDB 8000.
        $contribution = CapitalContribution::query()->findOrFail(
            trailContribute($shareholder, '30000000')->assertCreated()->json('data.id'),
        );

        // 4. CRDB 8000 → NMB 8010, an internal transfer.
        officerAt('Head Office', RoleName::Finance);
        $transfer = App\Models\BankTransfer::query()->findOrFail($this->postJson('/api/v1/bank-transfers', [
            'kind' => 'salary_advance',
            'fromAccountId' => trailAccount('8000')->id,
            'toAccountId' => trailAccount('8010')->id,
            'amount' => '30000000',
            'chargeFee' => '0',
            'reason' => 'Capital to operations',
        ])->assertCreated()->json('data.id'));

        // 5. Disbursement from NMB 8010.
        $loan = trailUnfundedLoanAtFinance();
        trailPrepare($loan)->assertCreated();
        trailSettle($loan)->assertOk();
        $batch = DisbursementBatch::query()->where('loan_id', $loan->id)->sole();

        // Each step has its own reference and its own entry…
        $contributionEntry = JournalEntry::query()->with('lines')->findOrFail($contribution->journal_entry_id);
        $transferEntry = JournalEntry::query()->with('lines')->findOrFail($transfer->journal_entry_id);
        $disbursementEntry = JournalEntry::query()->with('lines')->findOrFail($batch->journal_entry_id);

        expect([$contribution->reference, $transfer->reference, $batch->batch_reference])->each->not->toBeEmpty()
            ->and(collect([$contributionEntry, $transferEntry, $disbursementEntry])->pluck('id')->unique())->toHaveCount(3)
            ->and([$contributionEntry->source_type, $transferEntry->source_type, $disbursementEntry->source_type])
            ->toBe([JournalSourceType::CapitalInjection, JournalSourceType::Transfer, JournalSourceType::LoanDisbursement]);

        // …and the accounts join them: shareholder → 8000 → 8010 → loan.
        $crdb = trailAccount('8000')->chart_account_id;
        $nmb = trailAccount('8010')->chart_account_id;

        expect($contribution->shareholder_id)->toBe($shareholder->id)
            ->and((float) $contributionEntry->lines->firstWhere('account_id', $crdb)->debit_amount)->toBe(30000000.0)
            ->and((float) $transferEntry->lines->firstWhere('account_id', $crdb)->credit_amount)->toBe(30000000.0)
            ->and((float) $transferEntry->lines->firstWhere('account_id', $nmb)->debit_amount)->toBe(30000000.0)
            ->and($disbursementEntry->lines->firstWhere('account_id', $nmb)->credit_amount)->not->toBeNull()
            ->and($disbursementEntry->lines->firstWhere('account_id', $nmb)->loan_id)->toBe($loan->id)
            ->and($disbursementEntry->lines->firstWhere('account_id', $nmb)->customer_id)->toBe($loan->customer_id);

        // Nothing was reversed or rewritten along the way, and the books balance.
        expect(JournalEntry::query()->where('is_reversal', true)->count())->toBe(0)
            ->and((string) Illuminate\Support\Facades\DB::table('journal_entry_lines')->sum('debit_amount'))
            ->toBe((string) Illuminate\Support\Facades\DB::table('journal_entry_lines')->sum('credit_amount'));
    });
});
