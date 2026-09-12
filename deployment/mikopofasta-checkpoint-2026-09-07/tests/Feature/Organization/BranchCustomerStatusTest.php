<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Loans\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Loan;

/**
 * The Branch List's customer-status counts.
 *
 * Five numbers per branch: how many of its customers are lending, waiting, in
 * default, finished, and how many there are altogether.
 *
 * THE FOUR BUCKETS ARE MUTUALLY EXCLUSIVE, in that order of concern. A customer
 * holding both a defaulted loan and a healthy one is counted in DEFAULT and
 * nowhere else — an officer needs the worst thing about them, and counting them
 * twice would make the columns sum past the branch's own total.
 *
 * A customer with no loan is in `all` and none of the four, which is the honest
 * answer: they are on the book and none of the four states describes them.
 */
beforeEach(function (): void {
    /* A book with loans on it. Stops short of LedgerActivitySeeder, which
       needs demonstration bank accounts these counts have no use for. */
    seedLoanFoundation();
    test()->seed(Database\Seeders\UserSeeder::class);
    test()->seed(Database\Seeders\CustomerSeeder::class);
    test()->seed(Database\Seeders\LoanSeeder::class);
    forgetAuthGuards();

    $this->actingAs(userWithRole(RoleName::Admin), 'sanctum');

    /* A branch that actually has a book to count. */
    $this->customer = Customer::query()->whereHas('loans')->firstOrFail();
    $this->branch = Branch::query()->findOrFail($this->customer->branch_id);
});

/** The five counts for one branch, as the Branch List reads them. */
function statusFor(Branch $branch): array
{
    $row = collect(test()->getJson('/api/v1/branches')->assertOk()->json('data'))
        ->firstWhere('id', (string) $branch->getKey());

    return $row['customerStatus'];
}

/** Put every one of this customer's loans into one state. */
function putLoansIn(Customer $customer, string $status): void
{
    Loan::query()->where('customer_id', $customer->getKey())->update(['status' => $status]);
}

it('reports the branch total and four buckets', function (): void {
    $status = statusFor($this->branch);

    expect($status)->toHaveKeys(['active', 'pending', 'default', 'done', 'all'])
        ->and($status['all'])->toBe(Customer::query()->where('branch_id', $this->branch->getKey())->count());
});

it('moves a customer between buckets as their loans change', function (): void {
    $branch = $this->branch;

    foreach ([
        LoanStatus::Active->value => 'active',
        LoanStatus::Arrears->value => 'active',
        LoanStatus::PendingManagerApproval->value => 'pending',
        LoanStatus::AwaitingDisbursement->value => 'pending',
        LoanStatus::Defaulted->value => 'default',
        LoanStatus::WrittenOff->value => 'default',
        LoanStatus::Closed->value => 'done',
        LoanStatus::Recovered->value => 'done',
    ] as $loanStatus => $bucket) {
        putLoansIn($this->customer, $loanStatus);

        $status = statusFor($branch);

        expect($status[$bucket])
            ->toBeGreaterThan(0, "a {$loanStatus} loan should land its customer in {$bucket}");
    }
});

/*
 * The reason the buckets are exclusive rather than four independent counts.
 */
it('counts a customer holding several loans by their worst state', function (): void {
    $loans = Loan::query()->where('customer_id', $this->customer->getKey())->pluck('id');

    expect($loans->count())->toBeGreaterThan(0);

    /* Everything closed, then one loan defaulted on top. */
    putLoansIn($this->customer, LoanStatus::Closed->value);
    Loan::query()->whereKey($loans->first())->update(['status' => LoanStatus::Defaulted->value]);

    $withDefault = statusFor($this->branch);

    putLoansIn($this->customer, LoanStatus::Closed->value);
    $allClosed = statusFor($this->branch);

    expect($withDefault['default'])->toBe($allClosed['default'] + 1)
        ->and($withDefault['done'])->toBe($allClosed['done'] - 1);
});

it('never sums past the branch total', function (): void {
    foreach ([LoanStatus::Active, LoanStatus::Defaulted, LoanStatus::Closed] as $state) {
        putLoansIn($this->customer, $state->value);

        $status = statusFor($this->branch);

        expect($status['active'] + $status['pending'] + $status['default'] + $status['done'])
            ->toBeLessThanOrEqual($status['all']);
    }
});

it('counts each branch separately', function (): void {
    $other = Branch::query()->whereKeyNot($this->branch->getKey())->firstOrFail();

    $before = statusFor($other);
    putLoansIn($this->customer, LoanStatus::Defaulted->value);

    expect(statusFor($other))->toBe($before)
        ->and(statusFor($this->branch)['default'])->toBeGreaterThan(0);
});
