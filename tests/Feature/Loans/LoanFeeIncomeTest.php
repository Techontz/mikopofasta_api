<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Loans\Enums\ChargeValueType;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanFee;
use App\Models\LoanProduct;

/**
 * Loan Fee → Deducted Income, and the disbursement posting behind it.
 *
 * docs/modules/loan-charges.md listed four things wiring `loan_fees` into
 * disbursement would need; these tests cover all four.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

/**
 * Configures a fee on every product, before any loan is applied for.
 *
 * Order matters: the fee is snapshotted at application, so a loan created
 * before this runs carries no fee — which is itself asserted below.
 */
function configureLoanFee(string $amount = '5.00', ChargeValueType $type = ChargeValueType::PercentageValue, string $insurance = '0.00'): void
{
    foreach (LoanProduct::query()->get() as $product) {
        LoanFee::query()->updateOrCreate(
            ['loan_product_id' => $product->getKey()],
            ['fee_type' => $type, 'fee_amount' => $amount, 'insurance_amount' => $insurance],
        );
    }
}

function feeIncomeBalance(): float
{
    return (float) app(AccountResolver::class)->system(SystemAccountCode::FeeIncome)
        ->load('balances')->cachedBalance()->toDecimalString();
}

describe('snapshotting', function (): void {
    it('takes the fee terms at application, not at disbursement', function (): void {
        configureLoanFee();
        $loan = activeLoan();

        // The three snapshot columns hold the terms the borrower agreed to.
        expect($loan->fee_type_snapshot)->toBe(ChargeValueType::PercentageValue)
            ->and((float) $loan->fee_amount_snapshot)->toBe(5.0);
    });

    it('leaves a loan agreed before any fee existed uncharged', function (): void {
        // No configureLoanFee() — the product has no `loan_fees` row.
        $loan = activeLoan();

        // Null, not zero: "no fee was agreed" is a different fact from "a fee
        // of nothing was agreed", and only the second is a configured charge.
        expect($loan->fee_type_snapshot)->toBeNull()
            ->and($loan->fee_amount_snapshot)->toBeNull()
            ->and((float) $loan->fee_charged)->toBe(0.0);
    });

    it('is immune to the fee changing mid-term', function (): void {
        configureLoanFee('5.00');
        $loan = activeLoan();

        // Settings changes after the loan is agreed.
        configureLoanFee('20.00');

        // The borrower was quoted 5% and is charged 5%.
        expect((float) $loan->fresh()->fee_amount_snapshot)->toBe(5.0);
    });
});

describe('posting at disbursement', function (): void {
    it('credits Fee Income with the fee and Principal with the rest', function (): void {
        configureLoanFee();
        $before = feeIncomeBalance();

        $loan = activeLoan();
        $principal = (float) $loan->principal_amount;
        $fee = round($principal * 0.05, 2);

        expect((float) $loan->fee_charged)->toBe($fee);
        expect(feeIncomeBalance() - $before)->toBe($fee);

        $entry = JournalEntry::query()->with('lines')
            ->where('source_type', 'loan_disbursement')
            ->where('source_id', $loan->id)
            ->latest('id')->firstOrFail();

        // Dr Loan Receivable in full; the credit splits between Principal and
        // Fee Income. The borrower owes the whole principal either way — the
        // fee is deducted from the payout, not from the debt.
        expect($entry->lines)->toHaveCount(3);

        $debit = $entry->lines->firstWhere(fn ($l): bool => (float) $l->debit_amount > 0);
        expect((float) $debit->debit_amount)->toBe($principal);

        $feeAccountId = app(AccountResolver::class)->systemId(SystemAccountCode::FeeIncome);
        $feeLine = $entry->lines->firstWhere('account_id', $feeAccountId);
        expect((float) $feeLine->credit_amount)->toBe($fee);

        $principalAccountId = app(AccountResolver::class)->systemId(SystemAccountCode::Principal);
        $principalLine = $entry->lines->firstWhere('account_id', $principalAccountId);
        expect((float) $principalLine->credit_amount)->toBe(round($principal - $fee, 2));
    });

    it('posts a balanced entry', function (): void {
        configureLoanFee();
        $loan = activeLoan();

        $entry = JournalEntry::query()->with('lines')
            ->where('source_type', 'loan_disbursement')
            ->where('source_id', $loan->id)
            ->latest('id')->firstOrFail();

        expect($entry->lines->sum(fn ($l) => (float) $l->debit_amount))
            ->toBe($entry->lines->sum(fn ($l) => (float) $l->credit_amount));
    });

    it('posts two lines and no fee when the product charges nothing', function (): void {
        $before = feeIncomeBalance();
        $loan = activeLoan();

        $entry = JournalEntry::query()->with('lines')
            ->where('source_type', 'loan_disbursement')
            ->where('source_id', $loan->id)
            ->latest('id')->firstOrFail();

        // LedgerService rejects a zero-amount line, so the fee leg is omitted
        // rather than posted at zero.
        expect($entry->lines)->toHaveCount(2);
        expect(feeIncomeBalance())->toBe($before);
    });

    it('charges a flat fee as shillings rather than a percentage', function (): void {
        configureLoanFee('25000', ChargeValueType::MoneyValue);
        $before = feeIncomeBalance();

        $loan = activeLoan();

        // `fee_amount` means TZS when the type is money_value — the same
        // two-readings-of-one-column shape penalty_rate has.
        expect((float) $loan->fee_charged)->toBe(25000.0);
        expect(feeIncomeBalance() - $before)->toBe(25000.0);
    });

    it('adds the insurance premium to what is withheld', function (): void {
        configureLoanFee('5.00', ChargeValueType::PercentageValue, '10000.00');

        $loan = activeLoan();
        $expected = round((float) $loan->principal_amount * 0.05, 2) + 10000.0;

        expect((float) $loan->fee_charged)->toBe($expected);
    });
});

describe('the deducted income register', function (): void {
    it('lists what was withheld, with the loan it came from', function (): void {
        configureLoanFee();
        $loan = activeLoan();

        officerAt('Head Office', RoleName::Finance);

        $this->getJson('/api/v1/loan-fees/income')
            ->assertOk()
            ->assertJsonPath('data.0.loanNumber', $loan->loan_number)
            ->assertJsonPath('data.0.loanApproved', $loan->principal_amount)
            ->assertJsonPath('data.0.incomeAmount', $loan->fresh()->fee_charged)
            ->assertJsonStructure([
                'data' => [['id', 'customerName', 'branch', 'loanApproved', 'incomeAmount', 'date']],
            ]);
    });

    it('reports totals that tie to the Fee Income account', function (): void {
        configureLoanFee();
        activeLoan();

        officerAt('Head Office', RoleName::Finance);

        $response = $this->getJson('/api/v1/loan-fees/income')->assertOk();

        // The register and the ledger are the same events counted the same way.
        expect((float) $response->json('meta.totalIncome'))->toBe(feeIncomeBalance());
    });

    it('excludes a loan whose product charges nothing', function (): void {
        activeLoan();

        officerAt('Head Office', RoleName::Finance);

        // fee_charged is zero, and zero is not income.
        $this->getJson('/api/v1/loan-fees/income')->assertOk()->assertJsonCount(0, 'data');
    });

    it('shows the net the borrower actually received', function (): void {
        configureLoanFee();
        $loan = activeLoan();

        officerAt('Head Office', RoleName::Finance);

        $row = $this->getJson('/api/v1/loan-fees/income')->assertOk()->json('data.0');

        expect((float) $row['netDisbursed'])
            ->toBe((float) $loan->principal_amount - (float) $loan->fresh()->fee_charged);
    });
});

describe('authorization', function (): void {
    it('refuses an unauthenticated caller', function (): void {
        $this->getJson('/api/v1/loan-fees/income')->assertUnauthorized();
    });

    it('denies a role holding neither loans.view nor repayments.view', function (): void {
        // HR holds neither.
        actingAsRole(RoleName::Hr);

        $this->getJson('/api/v1/loan-fees/income')->assertForbidden();
    });
});
