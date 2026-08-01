<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use Illuminate\Support\Facades\DB;

/**
 * Query budgets for the endpoints that return collections.
 *
 * These are guards, not benchmarks. Laravel 12's
 * `Model::automaticallyEagerLoadRelationships()` prevents most N+1s, but it
 * cannot prevent one introduced inside a resource or a report — a `whenLoaded`
 * that quietly lazy-loads, a per-row lookup added to a summary. A budget
 * catches that as a failing test rather than as a slow page nobody profiles.
 *
 * The budgets are deliberately loose. A number that tightened to the exact
 * current count would fail on every harmless refactor and would be silenced
 * rather than investigated.
 */
beforeEach(function (): void {
    seedStaffBook();
    finalizedPayrollRun();
    forgetAuthGuards();
});

/**
 * Counts the queries one authenticated GET issues.
 *
 * @return array{status: int, queries: int}
 */
function queryCost(string $uri): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = test()->getJson($uri);

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    return ['status' => $response->getStatusCode(), 'queries' => $queries];
}

it('keeps every collection endpoint inside its query budget', function (): void {
    actingAsSeededUser('0754000001');

    /*
     * Budget per endpoint. The permission check, the branch scope lookup and
     * the paginator's count each cost a query before any data is read, which
     * is why even a trivial list sits around ten.
     */
    $budgets = [
        '/api/v1/customers' => 25,
        '/api/v1/loans' => 25,
        '/api/v1/payments' => 25,
        '/api/v1/staff' => 25,
        '/api/v1/payroll' => 25,
        '/api/v1/users' => 25,
        '/api/v1/branches' => 25,
        '/api/v1/loan-products' => 25,
        '/api/v1/ledger/entries' => 25,
        '/api/v1/ledger/accounts' => 25,
        '/api/v1/ledger/trial-balance' => 15,
        '/api/v1/commission' => 25,
        '/api/v1/staff/advances' => 25,
        '/api/v1/payments/suspense' => 25,
        '/api/v1/reports' => 15,
    ];

    $breaches = [];

    foreach ($budgets as $uri => $budget) {
        $cost = queryCost($uri);

        expect($cost['status'])->toBe(200, "{$uri} did not return 200");

        if ($cost['queries'] > $budget) {
            $breaches[] = sprintf('%s used %d queries (budget %d)', $uri, $cost['queries'], $budget);
        }
    }

    expect($breaches)->toBe([]);
});

it('does not grow its query count as rows are added', function (): void {
    actingAsSeededUser('0754000001');

    $before = queryCost('/api/v1/payments')['queries'];

    // Three more payments through the real intake path.
    $loan = App\Models\Loan::query()->where('status', 'active')->firstOrFail();
    officerAt($loan->branch->name, RoleName::Teller);

    foreach (['1000.00', '2000.00', '3000.00'] as $amount) {
        $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => $amount])
            ->assertCreated();
    }

    actingAsSeededUser('0754000001');
    $after = queryCost('/api/v1/payments')['queries'];

    // The definition of an N+1: cost rising with row count.
    expect($after)->toBe($before);
});

it('keeps every report inside its query budget', function (): void {
    $registry = app(App\Domain\Reports\Services\ReportRegistry::class);
    $filters = new App\Domain\Reports\DTOs\ReportFilters;

    $breaches = [];

    foreach ($registry->all() as $slug => $report) {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $report->compute($filters);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        /*
         * The per-branch reports issue one trial balance and one portfolio
         * read per branch, so their cost is O(branches) rather than O(rows).
         * The executive summary runs nine reports. Both are budgeted for the
         * shape they have, and the ceiling is what would catch a change that
         * made them O(rows) instead.
         */
        $budget = match ($slug) {
            'executive-summary' => 90,
            'branch-ranking' => 60,
            'branch-efficiency', 'branch-pnl' => 40,

            /*
             * Risk reads a trial balance per branch AND the open loan book, so
             * it is the union of what Branch P&L and Arrears each cost.
             */
            'risk' => 40,

            // Staff Fund asks each advance and loan what it still owes.
            'staff-fund' => 25,

            default => 20,
        };

        if ($queries > $budget) {
            $breaches[] = sprintf('%s used %d queries (budget %d)', $slug, $queries, $budget);
        }
    }

    expect($breaches)->toBe([]);
});
