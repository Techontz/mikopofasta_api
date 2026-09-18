<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\CustomerAdvances\Enums\CustomerAdvanceStatus;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerAdvance;
use App\Models\JournalEntry;
use App\Models\SalaryAdvanceCategory;
use App\Support\Money;

/**
 * Salary Advance (Customer) — the client's money rule, end to end.
 *
 * The rule these tests exist to hold:
 *
 *   - issuing takes the principal OUT of the operational account it is funded
 *     from, and creates a receivable — never a "Salary Advance" balance;
 *   - a collection returns the capital to that same operational account and
 *     recognises only the profit as income;
 *   - the Branch List reads one month and starts again at the next.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
    test()->seed(Database\Seeders\UserSeeder::class);
    test()->seed(Database\Seeders\CustomerSeeder::class);
    test()->seed(Database\Seeders\SalaryAdvanceCategorySeeder::class);
    forgetAuthGuards();
});

function anyCustomer(): Customer
{
    return Customer::query()->whereNotNull('branch_id')->firstOrFail();
}

function accountBalance(SystemAccountCode $code): Money
{
    return app(AccountResolver::class)->system($code)->load('balances')->cachedBalance();
}

function chartBalance(ChartOfAccount $account): Money
{
    return $account->fresh()->load('balances')->cachedBalance();
}

/**
 * An advance already disbursed, so the collection tests start where they mean
 * to. Returns the advance and the account the money left.
 *
 * @return array{0: CustomerAdvance, 1: ChartOfAccount}
 */
function disbursedAdvance(string $amount = '1000000.00'): array
{
    $customer = anyCustomer();

    actingAsRole(RoleName::SuperAdmin);

    $created = test()->postJson('/api/v1/customer-advances', [
        'customer_id' => $customer->getKey(),
        'amount' => $amount,
    ])->assertCreated()->json('data');

    $id = $created['id'];

    test()->postJson("/api/v1/customer-advances/{$id}/approve")->assertOk();
    test()->postJson("/api/v1/customer-advances/{$id}/disburse", ['from_cash' => true])->assertOk();

    $advance = CustomerAdvance::query()->findOrFail($id);

    return [$advance, ChartOfAccount::query()->findOrFail($advance->funding_account_id)];
}

describe('pricing', function (): void {
    it('prices a request from the band its amount falls into', function (): void {
        actingAsRole(RoleName::SuperAdmin);

        $band = SalaryAdvanceCategory::covering(Money::of('150000.00'));

        $row = $this->postJson('/api/v1/customer-advances', [
            'customer_id' => anyCustomer()->getKey(),
            'amount' => '150000.00',
        ])->assertCreated()->json('data');

        expect($row['categoryName'])->toBe($band->name)
            // 5% of 150,000 — the band's rate applied once, not per period.
            ->and($row['interest'])->toBe('7500.00')
            ->and($row['chargeFee'])->toBe($band->charge_fee)
            ->and($row['totalRepayable'])->toBe('159500.00')
            ->and($row['status'])->toBe('requested');
    });

    it('refuses an amount no band covers', function (): void {
        actingAsRole(RoleName::SuperAdmin);

        $this->postJson('/api/v1/customer-advances', [
            'customer_id' => anyCustomer()->getKey(),
            'amount' => '99000000.00',
        ])->assertUnprocessable();
    });

    it('refuses a second advance while one is in progress', function (): void {
        [$advance] = disbursedAdvance();

        $this->postJson('/api/v1/customer-advances', [
            'customer_id' => $advance->customer_id,
            'amount' => '150000.00',
        ])->assertStatus(409);
    });
});

describe('issuing', function (): void {
    it('takes the principal out of the funding account and books a receivable', function (): void {
        $receivableBefore = accountBalance(SystemAccountCode::CustomerAdvanceReceivable);

        [$advance, $funding] = disbursedAdvance('1000000.00');

        // Dr 1250 by the principal — the customer owes it.
        expect(accountBalance(SystemAccountCode::CustomerAdvanceReceivable)->toDecimalString())
            ->toBe($receivableBefore->add(Money::of('1000000.00'))->toDecimalString());

        // Cr the account it left. The till is an asset, so it is 1,000,000 down.
        $entry = JournalEntry::query()->findOrFail($advance->journal_entry_id);

        expect($entry->source_type)->toBe(JournalSourceType::CustomerAdvanceIssue)
            ->and($advance->status)->toBe(CustomerAdvanceStatus::Disbursed)
            ->and($advance->funding_account_id)->toBe($funding->getKey());

        $creditedLine = $entry->lines->firstWhere('account_id', $funding->getKey());

        expect($creditedLine)->not->toBeNull()
            ->and((float) $creditedLine->credit_amount)->toBe(1000000.0);
    });

    it('never posts to 1100 Principal, which is equity', function (): void {
        $before = accountBalance(SystemAccountCode::Principal);

        [$advance] = disbursedAdvance();

        expect(accountBalance(SystemAccountCode::Principal)->toDecimalString())
            ->toBe($before->toDecimalString());

        $principalAccountId = app(AccountResolver::class)->systemId(SystemAccountCode::Principal);

        expect(
            JournalEntry::query()->findOrFail($advance->journal_entry_id)
                ->lines->firstWhere('account_id', $principalAccountId),
        )->toBeNull();
    });

    it('refuses to disburse an advance that was never approved', function (): void {
        actingAsRole(RoleName::SuperAdmin);

        $id = $this->postJson('/api/v1/customer-advances', [
            'customer_id' => anyCustomer()->getKey(),
            'amount' => '150000.00',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/customer-advances/{$id}/disburse")->assertStatus(409);
    });
});

describe('collections', function (): void {
    it('returns the capital to the funding account and books only the profit', function (): void {
        // 1,000,000 at 10% + 10,000 fee = 1,110,000 repayable.
        [$advance, $funding] = disbursedAdvance('1000000.00');

        $fundingAfterIssue = chartBalance($funding);
        $interestBefore = accountBalance(SystemAccountCode::InterestIncome);
        $feeBefore = accountBalance(SystemAccountCode::FeeIncome);
        $receivableAfterIssue = accountBalance(SystemAccountCode::CustomerAdvanceReceivable);

        $payment = $this->postJson("/api/v1/customer-advances/{$advance->id}/payments", [
            'amount' => '222000.00',
            'channel' => 'cash',
        ])->assertCreated()->json('data');

        /*
         * 222,000 is a fifth of 1,110,000, so it carries a fifth of each part:
         * 200,000 capital, 20,000 interest, 2,000 fee. This is the client's own
         * worked example, to the shilling.
         */
        expect($payment['principalPortion'])->toBe('200000.00')
            ->and($payment['interestPortion'])->toBe('20000.00')
            ->and($payment['feePortion'])->toBe('2000.00')
            ->and($payment['profitPortion'])->toBe('22000.00');

        // The whole 222,000 arrived in the operational account.
        expect(chartBalance($funding)->toDecimalString())
            ->toBe($fundingAfterIssue->add(Money::of('222000.00'))->toDecimalString());

        // Only the capital cleared the receivable.
        expect(accountBalance(SystemAccountCode::CustomerAdvanceReceivable)->toDecimalString())
            ->toBe($receivableAfterIssue->subtract(Money::of('200000.00'))->toDecimalString());

        // The profit, and nothing but the profit, reached income.
        expect(accountBalance(SystemAccountCode::InterestIncome)->toDecimalString())
            ->toBe($interestBefore->add(Money::of('20000.00'))->toDecimalString())
            ->and(accountBalance(SystemAccountCode::FeeIncome)->toDecimalString())
            ->toBe($feeBefore->add(Money::of('2000.00'))->toDecimalString());
    });

    it('clears the receivable to exactly zero over a full run of payments', function (): void {
        [$advance] = disbursedAdvance('1000000.00');

        $receivableBefore = accountBalance(SystemAccountCode::CustomerAdvanceReceivable)
            ->subtract(Money::of('1000000.00'));

        // Three uneven payments, the last one whatever is left.
        foreach (['333333.33', '333333.33'] as $amount) {
            $this->postJson("/api/v1/customer-advances/{$advance->id}/payments", [
                'amount' => $amount,
                'channel' => 'cash',
            ])->assertCreated();
        }

        $remaining = $this->getJson("/api/v1/customer-advances/{$advance->id}")
            ->assertOk()->json('data.remaining');

        $this->postJson("/api/v1/customer-advances/{$advance->id}/payments", [
            'amount' => $remaining,
            'channel' => 'cash',
        ])->assertCreated();

        $settled = CustomerAdvance::query()->findOrFail($advance->getKey());

        expect($settled->status)->toBe(CustomerAdvanceStatus::Settled)
            ->and($settled->principal_repaid)->toBe('1000000.00')
            ->and($settled->profitRepaidMoney()->toDecimalString())->toBe('110000.00')
            // The receivable is back to where it stood before the advance.
            ->and(accountBalance(SystemAccountCode::CustomerAdvanceReceivable)->toDecimalString())
            ->toBe($receivableBefore->toDecimalString());
    });

    it('balances a payment too small for its own rounding', function (): void {
        // One cent against a million-shilling advance: the capital and interest
        // shares both round up, and an unclamped residual fee would go negative
        // and unbalance the entry.
        [$advance] = disbursedAdvance('1000000.00');

        $this->postJson("/api/v1/customer-advances/{$advance->id}/payments", [
            'amount' => '0.01',
            'channel' => 'cash',
        ])->assertCreated()
            ->assertJsonPath('data.amount', '0.01');

        $payment = App\Models\CustomerAdvancePayment::query()->latest('id')->firstOrFail();

        expect($payment->principalMoney()->add($payment->profitMoney())->toDecimalString())
            ->toBe('0.01');
    });

    it('refuses to collect more than is outstanding', function (): void {
        [$advance] = disbursedAdvance('1000000.00');

        $this->postJson("/api/v1/customer-advances/{$advance->id}/payments", [
            'amount' => '1110000.01',
            'channel' => 'cash',
        ])->assertUnprocessable();
    });

    it('refuses a payment against an advance that has not been disbursed', function (): void {
        actingAsRole(RoleName::SuperAdmin);

        $id = $this->postJson('/api/v1/customer-advances', [
            'customer_id' => anyCustomer()->getKey(),
            'amount' => '150000.00',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/customer-advances/{$id}/payments", [
            'amount' => '1000.00',
            'channel' => 'cash',
        ])->assertStatus(409);
    });
});

describe('branch list', function (): void {
    it('reports the month it is asked for and nothing from the month before', function (): void {
        [$advance] = disbursedAdvance('1000000.00');

        $this->postJson("/api/v1/customer-advances/{$advance->id}/payments", [
            'amount' => '222000.00',
            'channel' => 'cash',
        ])->assertCreated();

        $thisMonth = $this->getJson('/api/v1/dashboard/branch-summary')->assertOk()->json('data');

        $row = collect($thisMonth['rows'])->firstWhere('branchId', (string) $advance->branch_id);

        expect($thisMonth['monthLabel'])->toBe(now()->format('F Y'))
            ->and($row['salaryAdvancePaid'])->toBe('222000.00')
            ->and($row['salaryAdvanceIssued'])->toBe('1000000.00')
            // The capital is in the Principal column, the profit in the others.
            ->and($row['principal'])->toBe('200000.00')
            ->and($row['interest'])->toBe('20000.00')
            ->and($row['loanFee'])->toBe('2000.00')
            // Stated so the dialog can say the column is a summary, not a pot.
            ->and($thisMonth['salaryAdvanceIsSummary'])->toBeTrue();

        $lastMonth = $this->getJson(
            '/api/v1/dashboard/branch-summary?month='.now()->subMonthNoOverflow()->format('Y-m'),
        )->assertOk()->json('data');

        $lastRow = collect($lastMonth['rows'])->firstWhere('branchId', (string) $advance->branch_id);

        expect($lastRow['salaryAdvancePaid'])->toBe('0.00')
            ->and($lastRow['salaryAdvanceIssued'])->toBe('0.00');
    });
});

describe('permissions', function (): void {
    it('lets a teller collect but not approve', function (): void {
        [$advance] = disbursedAdvance('1000000.00');

        forgetAuthGuards();
        actingAsRole(RoleName::Teller);

        $this->postJson("/api/v1/customer-advances/{$advance->id}/payments", [
            'amount' => '1000.00',
            'channel' => 'cash',
        ])->assertCreated();

        $this->postJson("/api/v1/customer-advances/{$advance->id}/approve")->assertForbidden();
    });
});
