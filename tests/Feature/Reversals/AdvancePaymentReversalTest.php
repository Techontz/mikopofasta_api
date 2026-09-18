<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\CustomerAdvances\Enums\CustomerAdvanceStatus;
use App\Domain\Ledger\Enums\ReversalStatus;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerAdvance;
use App\Models\CustomerAdvancePayment;
use App\Models\ReversalRequest;
use App\Support\Money;

/**
 * Reversing a customer salary advance payment.
 *
 * What is being held: a reversed collection leaves every figure it touched —
 * the funding account, the receivable, both income accounts, the advance's own
 * repaid columns and the dashboard summary — exactly where it stood before the
 * money arrived, to the cent.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
    test()->seed(Database\Seeders\UserSeeder::class);
    test()->seed(Database\Seeders\CustomerSeeder::class);
    test()->seed(Database\Seeders\SalaryAdvanceCategorySeeder::class);
    forgetAuthGuards();
});

/** @return array{0: CustomerAdvance, 1: ChartOfAccount} */
function advanceForReversal(string $amount = '1000000.00'): array
{
    actingAsRole(RoleName::SuperAdmin);

    $id = test()->postJson('/api/v1/customer-advances', [
        'customer_id' => Customer::query()->whereNotNull('branch_id')->firstOrFail()->getKey(),
        'amount' => $amount,
    ])->assertCreated()->json('data.id');

    test()->postJson("/api/v1/customer-advances/{$id}/approve")->assertOk();
    test()->postJson("/api/v1/customer-advances/{$id}/disburse", ['from_cash' => true])->assertOk();

    $advance = CustomerAdvance::query()->findOrFail($id);

    return [$advance, ChartOfAccount::query()->findOrFail($advance->funding_account_id)];
}

function collectOnAdvance(CustomerAdvance $advance, string $amount): CustomerAdvancePayment
{
    actingAsRole(RoleName::SuperAdmin);

    $id = test()->postJson("/api/v1/customer-advances/{$advance->id}/payments", [
        'amount' => $amount,
        'channel' => 'cash',
    ])->assertCreated()->json('data.id');

    return CustomerAdvancePayment::query()->findOrFail($id);
}

/** Finance raises it, Admin approves it — the arrangement the client asked for. */
function reverseAdvancePayment(CustomerAdvancePayment $payment): ReversalRequest
{
    officerAt('Head Office', RoleName::Finance);

    test()->postJson('/api/v1/reversals', [
        'reversal_type' => 'advance_payment',
        'subject_id' => $payment->getKey(),
        'reason' => 'Collected against the wrong customer',
    ])->assertCreated();

    $request = ReversalRequest::query()->latest('id')->firstOrFail();

    actingAsRole(RoleName::Admin);
    test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();

    return $request->fresh();
}

function systemBalance(SystemAccountCode $code): string
{
    return app(AccountResolver::class)->system($code)->load('balances')->cachedBalance()->toDecimalString();
}

function fundingBalance(ChartOfAccount $account): string
{
    return $account->fresh()->load('balances')->cachedBalance()->toDecimalString();
}

describe('reversing a salary advance payment', function (): void {
    it('returns every account and total to where it stood, to the cent', function (): void {
        [$advance, $funding] = advanceForReversal('1000000.00');

        $before = [
            'funding' => fundingBalance($funding),
            'receivable' => systemBalance(SystemAccountCode::CustomerAdvanceReceivable),
            'interest' => systemBalance(SystemAccountCode::InterestIncome),
            'fee' => systemBalance(SystemAccountCode::FeeIncome),
        ];

        $payment = collectOnAdvance($advance, '222000.00');

        $request = reverseAdvancePayment($payment);

        expect($request->status)->toBe(ReversalStatus::Approved)
            ->and($request->amount)->toBe('222000.00')
            ->and($request->reversal_entry_id)->not->toBeNull()
            ->and(fundingBalance($funding))->toBe($before['funding'])
            ->and(systemBalance(SystemAccountCode::CustomerAdvanceReceivable))->toBe($before['receivable'])
            ->and(systemBalance(SystemAccountCode::InterestIncome))->toBe($before['interest'])
            ->and(systemBalance(SystemAccountCode::FeeIncome))->toBe($before['fee'])
            ->and(app(TrialBalanceBuilder::class)->build()['balanced'])->toBeTrue();

        $advance->refresh();

        expect($advance->amount_repaid)->toBe('0.00')
            ->and($advance->principal_repaid)->toBe('0.00')
            ->and($advance->interest_repaid)->toBe('0.00')
            ->and($advance->fee_repaid)->toBe('0.00')
            ->and($payment->fresh()->isReversed())->toBeTrue()
            ->and($payment->fresh()->reversal_entry_id)->toBe($request->reversal_entry_id);
    });

    it('reopens an advance the payment had settled, owing exactly that payment', function (): void {
        [$advance] = advanceForReversal('1000000.00');

        collectOnAdvance($advance, '222000.00');

        actingAsRole(RoleName::SuperAdmin);
        $remaining = test()->getJson("/api/v1/customer-advances/{$advance->id}")->json('data.remaining');

        $last = collectOnAdvance($advance, $remaining);

        expect($advance->fresh()->status)->toBe(CustomerAdvanceStatus::Settled);

        reverseAdvancePayment($last);

        $reopened = $advance->fresh();

        actingAsRole(RoleName::SuperAdmin);

        expect($reopened->status)->toBe(CustomerAdvanceStatus::Disbursed)
            ->and($reopened->settled_at)->toBeNull()
            ->and($reopened->amount_repaid)->toBe('222000.00')
            ->and(test()->getJson("/api/v1/customer-advances/{$advance->id}")->json('data.remaining'))
            ->toBe($remaining);
    });

    it('still closes the receivable to exactly zero after an earlier payment is reversed', function (): void {
        [$advance] = advanceForReversal('1000000.00');

        $receivableBefore = Money::of(systemBalance(SystemAccountCode::CustomerAdvanceReceivable))
            ->subtract(Money::of('1000000.00'));

        $first = collectOnAdvance($advance, '333333.33');
        collectOnAdvance($advance, '333333.33');

        reverseAdvancePayment($first);

        actingAsRole(RoleName::SuperAdmin);
        $remaining = test()->getJson("/api/v1/customer-advances/{$advance->id}")->json('data.remaining');
        collectOnAdvance($advance, $remaining);

        $settled = $advance->fresh();

        expect($settled->status)->toBe(CustomerAdvanceStatus::Settled)
            ->and($settled->principal_repaid)->toBe('1000000.00')
            ->and($settled->profitRepaidMoney()->toDecimalString())->toBe('110000.00')
            ->and(systemBalance(SystemAccountCode::CustomerAdvanceReceivable))
            ->toBe($receivableBefore->toDecimalString());
    });

    it('drops the reversed payment from the Branch List and the payments totals', function (): void {
        [$advance] = advanceForReversal('1000000.00');

        $payment = collectOnAdvance($advance, '222000.00');
        reverseAdvancePayment($payment);

        actingAsRole(RoleName::SuperAdmin);

        $row = collect(test()->getJson('/api/v1/dashboard/branch-summary')->assertOk()->json('data.rows'))
            ->firstWhere('branchId', (string) $advance->branch_id);

        expect($row['salaryAdvancePaid'])->toBe('0.00')
            ->and($row['principal'])->toBe('0.00')
            ->and($row['interest'])->toBe('0.00')
            ->and($row['loanFee'])->toBe('0.00');

        $list = test()->getJson('/api/v1/customer-advances/payments')->assertOk();

        // Still listed — it is the history — but flagged, and not counted.
        expect($list->json('data.0.reversed'))->toBeTrue()
            ->and($list->json('meta.totalPaid') ?? $list->json('totalPaid'))->toBe('0.00');
    });

    it('refuses to reverse the same payment twice', function (): void {
        [$advance] = advanceForReversal('1000000.00');

        $payment = collectOnAdvance($advance, '222000.00');
        reverseAdvancePayment($payment);

        officerAt('Head Office', RoleName::Finance);

        test()->postJson('/api/v1/reversals', [
            'reversal_type' => 'advance_payment',
            'subject_id' => $payment->getKey(),
            'reason' => 'Again',
        ])->assertStatus(409);
    });
});
