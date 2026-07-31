<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Support\Money;

/**
 * Penalty → Penalty List and Penalty → Paid Penalty.
 *
 * Both are reads over records other modules write: the overdue job accrues onto
 * `loan_schedules.penalty_due`, the repayment engine collects onto
 * `payment_allocations.penalty_allocated`. Nothing here creates a penalty, so
 * every fixture below goes through those paths rather than writing rows.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

/**
 * An active loan whose first installment is overdue and penalised.
 *
 * Driven through the real overdue endpoint so the penalty under test is one
 * PenaltyCalculator actually produced.
 */
function loanWithPenalty(int $daysOverdue = 30): Loan
{
    $loan = activeLoan();

    $loan->schedules->sortBy('installment_number')->first()
        ->update(['due_date' => now()->subDays($daysOverdue)->toDateString()]);

    officerAt('Head Office', RoleName::Finance);
    test()->postJson('/api/v1/loans/overdue/process')->assertOk();

    return $loan->fresh(['schedules', 'customer', 'branch']);
}

function firstPenalisedSchedule(Loan $loan): LoanSchedule
{
    return $loan->schedules()->where('penalty_due', '>', 0)
        ->orderBy('installment_number')->firstOrFail();
}

describe('the accrued register', function (): void {
    it('lists an installment carrying a penalty, in the shape the screen draws', function (): void {
        $loan = loanWithPenalty();
        $schedule = firstPenalisedSchedule($loan);

        $this->getJson('/api/v1/penalties')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customerName', $loan->customer->fullName())
            ->assertJsonPath('data.0.loanAmount', $loan->principal_amount)
            ->assertJsonPath('data.0.penaltyAmount', $schedule->penalty_due)
            // Dated by the installment's due date: the date the penalty is
            // about, not the date the job happened to run.
            ->assertJsonPath('data.0.date', $schedule->due_date->toDateString())
            ->assertJsonStructure([
                'data' => [['id', 'customerName', 'branch', 'loanAmount', 'penaltyAmount', 'date']],
            ]);
    });

    it('excludes an installment that never went overdue', function (): void {
        activeLoan();
        officerAt('Head Office', RoleName::Finance);

        // penalty_due is zero on every schedule, and zero is not a penalty.
        $this->getJson('/api/v1/penalties')->assertOk()->assertJsonCount(0, 'data');
    });

    it('reports charged, paid and outstanding separately', function (): void {
        $loan = loanWithPenalty();
        $schedule = firstPenalisedSchedule($loan);

        $response = $this->getJson('/api/v1/penalties')->assertOk();

        expect($response->json('meta.totalCharged'))->toBe($schedule->penalty_due)
            ->and($response->json('meta.totalPaid'))->toBe('0.00')
            ->and($response->json('meta.totalOutstanding'))->toBe($schedule->penalty_due);
    });

    it('keeps showing the charge once part of it is paid, with what remains', function (): void {
        $loan = loanWithPenalty();
        $schedule = firstPenalisedSchedule($loan);
        $charged = Money::of($schedule->penalty_due);

        // Allocation order is Penalty → Interest → Principal, so a small
        // payment lands entirely on the penalty.
        $part = Money::ofMinor((int) floor($charged->minor / 2));

        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $part->toDecimalString(),
        ])->assertCreated();

        $row = $this->getJson('/api/v1/penalties')->assertOk()->json('data.0');

        // The charge does not shrink when paid — that is what `outstanding` is
        // for, and why both are on the row.
        expect($row['penaltyAmount'])->toBe($charged->toDecimalString())
            ->and($row['penaltyPaid'])->toBe($part->toDecimalString())
            ->and($row['outstanding'])->toBe($charged->subtract($part)->toDecimalString());
    });
});

describe('the paid register', function (): void {
    it('lists collected penalty money dated by the payment', function (): void {
        $loan = loanWithPenalty();
        $schedule = firstPenalisedSchedule($loan);
        $charged = Money::of($schedule->penalty_due);

        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $charged->toDecimalString(),
        ])->assertCreated();

        $this->getJson('/api/v1/penalties/paid')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customerName', $loan->customer->fullName())
            ->assertJsonPath('data.0.paidAmount', $charged->toDecimalString())
            ->assertJsonPath('data.0.date', now()->toDateString())
            ->assertJsonStructure([
                'data' => [['id', 'customerName', 'branch', 'paidAmount', 'date']],
            ]);
    });

    it('is empty until a penalty is actually collected', function (): void {
        loanWithPenalty();

        // Accrued is not collected.
        $this->getJson('/api/v1/penalties/paid')->assertOk()->assertJsonCount(0, 'data');
    });

    it('totals what the Penalty Income account holds', function (): void {
        $loan = loanWithPenalty();
        $charged = Money::of(firstPenalisedSchedule($loan)->penalty_due);

        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $charged->toDecimalString(),
        ])->assertCreated();

        $response = $this->getJson('/api/v1/penalties/paid')->assertOk();

        /*
         * The register and 2200 Penalty Income count the same events. §5
         * recognises penalty income on collection, which is exactly what this
         * screen lists — so if these two ever disagreed, one of them would be
         * wrong about how much the company earned.
         */
        $account = app(AccountResolver::class)->system(SystemAccountCode::PenaltyIncome)->load('balances');

        expect($response->json('meta.totalPaid'))->toBe($account->cachedBalance()->toDecimalString());
    });
});

describe('filtering, searching and sorting', function (): void {
    it('narrows to a branch', function (): void {
        $loan = loanWithPenalty();

        $this->getJson('/api/v1/penalties?branch_id='.$loan->branch_id)
            ->assertOk()->assertJsonCount(1, 'data');

        $other = App\Models\Branch::query()->whereKeyNot($loan->branch_id)->firstOrFail();

        $this->getJson('/api/v1/penalties?branch_id='.$other->id)
            ->assertOk()->assertJsonCount(0, 'data');
    });

    it('narrows to a date range on the installment due date', function (): void {
        $loan = loanWithPenalty(30);
        $due = firstPenalisedSchedule($loan)->due_date;

        $this->getJson('/api/v1/penalties?from='.$due->toDateString().'&to='.$due->toDateString())
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/penalties?from='.$due->addDay()->toDateString())
            ->assertOk()->assertJsonCount(0, 'data');
    });

    it('searches by customer name and by loan number', function (): void {
        $loan = loanWithPenalty();

        $this->getJson('/api/v1/penalties?search='.urlencode($loan->customer->first_name))
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/penalties?search='.urlencode($loan->loan_number))
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/penalties?search=nobodybythisname')
            ->assertOk()->assertJsonCount(0, 'data');
    });

    it('refuses a range that ends before it starts', function (): void {
        loanWithPenalty();

        // Returning nothing would read as "no data" rather than "bad filter".
        $this->getJson('/api/v1/penalties?from=2026-06-01&to=2026-01-01')
            ->assertStatus(422)
            ->assertJsonPath('errors.to.0', 'The end of the range cannot fall before its start.');
    });

    it('sorts by amount', function (): void {
        loanWithPenalty();
        forgetAuthGuards();
        loanWithPenalty();

        $ascending = collect(
            $this->getJson('/api/v1/penalties?sort=amount&direction=asc')->assertOk()->json('data'),
        )->pluck('penaltyAmount')->map(fn ($v) => (float) $v)->all();

        expect($ascending)->toBe(collect($ascending)->sort()->values()->all());
    });
});

describe('pagination', function (): void {
    it('paginates and reports the page shape §1 declares', function (): void {
        loanWithPenalty();
        forgetAuthGuards();
        loanWithPenalty();

        $this->getJson('/api/v1/penalties?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.perPage', 1)
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.pagination.lastPage', 2);
    });

    it('clamps per_page to the documented maximum', function (): void {
        loanWithPenalty();

        $this->getJson('/api/v1/penalties?per_page=5000')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
    });

    it('keeps the totals over the whole set, not just the visible page', function (): void {
        loanWithPenalty();
        forgetAuthGuards();
        loanWithPenalty();

        $all = $this->getJson('/api/v1/penalties')->assertOk()->json('meta.totalCharged');

        // A footer that only added up the visible page would be a different
        // number on page two, which is the classic way a report lies.
        $this->getJson('/api/v1/penalties?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.totalCharged', $all);
    });
});

describe('branch scope', function (): void {
    it('hides another branch penalties from a branch-scoped role', function (): void {
        $loan = loanWithPenalty();

        // A loan officer sees only their own branch (§13).
        forgetAuthGuards();
        $elsewhere = App\Models\Branch::query()->whereKeyNot($loan->branch_id)->firstOrFail();
        officerAt($elsewhere->name, RoleName::LoanOfficer);

        $this->getJson('/api/v1/penalties')->assertOk()->assertJsonCount(0, 'data');
    });

    it('shows a branch its own penalties', function (): void {
        $loan = loanWithPenalty();

        forgetAuthGuards();
        officerAt($loan->branch->name, RoleName::LoanOfficer);

        $this->getJson('/api/v1/penalties')->assertOk()->assertJsonCount(1, 'data');
    });
});

describe('authorization', function (): void {
    it('refuses an unauthenticated caller', function (): void {
        $this->getJson('/api/v1/penalties')->assertUnauthorized();
        $this->getJson('/api/v1/penalties/paid')->assertUnauthorized();
    });

    it('allows a repayments role as well as a loans one', function (): void {
        // A penalty is both a term of the loan and money to collect, so either
        // grant opens the register.
        actingAsRole(RoleName::Teller);

        $this->getJson('/api/v1/penalties')->assertOk();
    });

    it('denies a role holding neither grant', function (): void {
        actingAsRole(RoleName::Hr);

        $this->getJson('/api/v1/penalties')->assertForbidden();
        $this->getJson('/api/v1/penalties/paid')->assertForbidden();
    });
});
