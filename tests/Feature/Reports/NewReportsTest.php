<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\Services\CommissionCalculator;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\Services\ReportSources;
use App\Models\CommissionPool;
use App\Support\Money;

/**
 * Module 8 — the ten reports the reports document names that §15.6 omits.
 *
 * Each maps to a numbered section of that document; the mapping is asserted
 * here as well as stated in each report's docblock, so deleting one is a test
 * failure rather than a quietly shorter catalogue.
 *
 * See docs/modules/reports.md.
 */
beforeEach(function (): void {
    seedStaffBook();
    finalizedPayrollRun();

    /*
     * Expenses are not part of the staff book, and three of these reports are
     * about nothing else. Seeded through ExpenseSeeder rather than by inserting
     * rows, so each approval posts a real double entry — which is what lets the
     * Branch Expense total be checked against Branch P&L below.
     */
    test()->seed(Database\Seeders\ExpenseSeeder::class);

    forgetAuthGuards();
});

/** Every report the reports document asks for, by the name it uses. */
const DOCUMENTED_REPORTS = [
    'Loan Portfolio' => 'portfolio',
    'Repayment' => 'repayment',
    'Default & Arrears' => 'arrears',
    'Recovery' => 'recovery',
    'Branch Profit & Loss' => 'branch-pnl',
    'Branch Expense' => 'branch-expense',
    'Branch Efficiency' => 'branch-efficiency',
    'HQ Cash Flow' => 'hq-cashflow',
    'HQ Expenses' => 'hq-expense',
    'HQ Allocation' => 'hq-allocation',
    'Payroll' => 'payroll',
    'Payslip' => 'staff-payslip',
    'Commission' => 'commission',
    'Staff Performance' => 'performance',
    'Staff Fund' => 'staff-fund',
    'Staff Loan' => 'staff-loan',
    'Staff Advance' => 'staff-advance',
    'Profit Adjustment' => 'profit-adjustment',
    'Commission Eligibility' => 'commission-eligibility',
    'Financial Statements' => 'financial-statements',
    'Balance Sheet' => 'balance-sheet',
    'Cash Position' => 'cash-position',
    'Audit Trail' => 'audit-trail',
    'Suspense' => 'suspense',
    'Reversal' => 'reversals',
    'Daily Collection' => 'daily-collection',
    'Daily Disbursement' => 'daily-disbursement',
    'Daily Position' => 'daily-position',
    'Branch Ranking' => 'branch-ranking',
    'Growth' => 'growth',
    'Risk' => 'risk',
    'Customer Segmentation' => 'segmentation',
    'Age Analysis' => 'age-analysis',
    'Repayment Behaviour' => 'repayment-behavior',
];

it('publishes every report the documentation names', function (): void {
    actingAsFinance();

    $catalogue = collect($this->getJson('/api/v1/reports')->assertOk()->json('data'))
        ->keyBy('slug');

    foreach (DOCUMENTED_REPORTS as $documentName => $slug) {
        expect($catalogue->has($slug))->toBeTrue("\"{$documentName}\" is missing (expected slug {$slug})");
    }
});

it('serves every one of them over HTTP', function (): void {
    actingAsFinance();

    foreach (DOCUMENTED_REPORTS as $documentName => $slug) {
        $response = $this->getJson("/api/v1/reports/{$slug}");

        expect($response->getStatusCode())->toBe(200, "{$documentName} ({$slug}) did not return 200");
        expect($response->json('meta.report.slug'))->toBe($slug);
        // Every report states how it ties back — §15.6's traceability rule.
        expect($response->json('meta.reconciliation'))->not->toBeNull("{$slug} has no reconciliation note");
    }
});

// ---------------------------------------------------------------------------
// §3C, §4B, §4C — expenses and the head office hold
// ---------------------------------------------------------------------------

describe('branch expense (§3C)', function (): void {
    it('lists approved branch expenses by category', function (): void {
        actingAsFinance();

        $rows = $this->getJson('/api/v1/reports/branch-expense')->assertOk()->json('data');

        expect($rows)->not->toBeEmpty()
            ->and($rows[0])->toHaveKeys(['reference', 'branch', 'category', 'paidBy', 'amount']);
    });

    /*
     * A pending request has not been decided and a rejected one never happened.
     * Approval is also the moment the expense posts, so anything else would put
     * a figure in an expense report that no journal line supports.
     */
    it('counts approved requests only', function (): void {
        actingAsFinance();

        $references = collect($this->getJson('/api/v1/reports/branch-expense')->assertOk()->json('data'))
            ->pluck('reference');

        $rejected = App\Models\ExpenseRequest::query()
            ->where('status', App\Domain\Expenses\Enums\ExpenseRequestStatus::Rejected)
            ->value('reference');

        $pending = App\Models\ExpenseRequest::query()
            ->where('status', App\Domain\Expenses\Enums\ExpenseRequestStatus::Pending)
            ->value('reference');

        expect($references)->not->toContain($rejected)->not->toContain($pending);
    });

    it('marks which expenses head office paid for — §3C', function (): void {
        actingAsFinance();

        $paidBy = collect($this->getJson('/api/v1/reports/branch-expense')->assertOk()->json('data'))
            ->pluck('paidBy')->unique();

        expect($paidBy)->toContain('Head Office');
    });

    /*
     * It ties to the expense-category chart accounts, NOT to Branch P&L's
     * Expense column — that column also carries salary, commission and bank
     * charges, none of which is raised as an expense request. Asserting the
     * looser, true relationship is the point: the first draft of this report
     * claimed equality with Branch P&L and was wrong.
     */
    it('ties to the expense-category accounts, and is a subset of Branch P&L', function (): void {
        actingAsFinance();

        $branchId = App\Models\Branch::query()->where('name', 'Missenyi')->value('id');

        $reported = Money::of(
            $this->getJson("/api/v1/reports/branch-expense?branch_id={$branchId}")
                ->assertOk()->json('meta.totals.amount'),
        );

        $categoryAccounts = App\Models\ExpenseCategory::query()
            ->whereNotNull('chart_account_id')
            ->pluck('chart_account_id');

        $posted = Money::of((string) (Illuminate\Support\Facades\DB::table('journal_entry_lines')
            ->whereIn('account_id', $categoryAccounts)
            ->where('branch_id', $branchId)
            ->sum('debit_amount') ?: '0'));

        expect($reported->toDecimalString())->toBe($posted->toDecimalString());

        $pnl = collect($this->getJson("/api/v1/reports/branch-pnl?branch_id={$branchId}")
            ->assertOk()->json('data'))->first();

        // A subset: the P&L column is larger because payroll posts there too.
        expect(bccomp((string) $pnl['expense'], $reported->toDecimalString(), 2))
            ->toBeGreaterThanOrEqual(0);
    });
});

describe('HQ expense (§4B)', function (): void {
    it('groups by month so a reader can compare — §4B', function (): void {
        actingAsFinance();

        $rows = $this->getJson('/api/v1/reports/hq-expense')->assertOk()->json('data');

        expect(count($rows))->toBeGreaterThan(1)
            ->and($rows[0])->toHaveKeys(['month', 'total', 'change', 'changePct', 'topCategory']);
    });

    it('leaves the earliest month without a change figure', function (): void {
        actingAsFinance();

        $rows = $this->getJson('/api/v1/reports/hq-expense')->assertOk()->json('data');

        // Newest first, so the earliest month is last — and a change from
        // nothing would read as infinite growth.
        expect(end($rows)['change'])->toBe('—');
    });

    it('refuses to pretend it can be scoped to a branch', function (): void {
        actingAsFinance();

        $branchId = App\Models\Branch::query()->value('id');

        $filters = $this->getJson("/api/v1/reports/hq-expense?branch_id={$branchId}")
            ->assertOk()->json('meta.filters_applied');

        // "HQ expenses at Kakonko" is not a question with an answer, so the
        // filter is dropped rather than silently honoured.
        expect($filters)->not->toHaveKey('branch_id');
    });
});

describe('HQ allocation (§4C)', function (): void {
    it('shows the 2% held against each branch profit', function (): void {
        actingAsFinance();

        $response = $this->getJson('/api/v1/reports/hq-allocation')->assertOk();
        $rows = $response->json('data');

        expect($rows)->not->toBeEmpty()
            ->and($rows[0]['holdRate'])->toBe(CommissionCalculator::HQ_HOLD_RATE);

        // Each row's hold is 2% of that row's branch profit.
        foreach ($rows as $row) {
            $expected = Money::of((string) $row['branchProfit'])
                ->percentage(App\Support\Percentage::of(CommissionCalculator::HQ_HOLD_RATE));

            expect($row['held'])->toBe($expected->toDecimalString());
        }
    });

    it('reports the accumulated reserve unfiltered', function (): void {
        actingAsFinance();

        $reserve = Money::sum(
            CommissionPool::query()->pluck('hq_hold_amount')->map(fn (string $v): Money => Money::of($v)),
        );

        // "Total accumulated" is not a filtered quantity, so narrowing to one
        // period must not change it.
        $summary = collect($this->getJson('/api/v1/reports/hq-allocation?period=2026-08')
            ->assertOk()->json('meta.summary'));

        expect($summary->firstWhere('label', 'Accumulated Reserve')['value'])
            ->toBe($reserve->toDecimalString());
    });
});

// ---------------------------------------------------------------------------
// §6B, §6C — profit control
// ---------------------------------------------------------------------------

describe('profit adjustment (§6B)', function (): void {
    it('lays out profit less hold less loss, per row', function (): void {
        actingAsFinance();

        $rows = $this->getJson('/api/v1/reports/profit-adjustment')->assertOk()->json('data');

        expect($rows)->not->toBeEmpty();

        foreach ($rows as $row) {
            $after = Money::of((string) $row['profitBefore'])
                ->subtract(Money::of((string) $row['hqHold']))
                ->subtract(Money::of((string) $row['lossDeducted']));

            expect($row['profitAfter'])->toBe($after->toDecimalString());
        }
    });

    it('names the outcome in a word', function (): void {
        actingAsFinance();

        $outcomes = collect($this->getJson('/api/v1/reports/profit-adjustment')->assertOk()->json('data'))
            ->pluck('outcome')->unique();

        foreach ($outcomes as $outcome) {
            expect(['Distributed', 'Absorbed by loss', 'Blocked (loss)'])->toContain($outcome);
        }
    });
});

describe('commission eligibility (§6C)', function (): void {
    it('says which branches were blocked and why', function (): void {
        actingAsFinance();

        $rows = $this->getJson('/api/v1/reports/commission-eligibility')->assertOk()->json('data');

        expect($rows)->not->toBeEmpty();

        foreach ($rows as $row) {
            expect(['Eligible', 'Blocked'])->toContain($row['status']);
            // A reason is the point: "Blocked" alone invites the question.
            expect($row['reason'])->not->toBeEmpty();
        }
    });

    it('agrees with the pool’s own eligibility test', function (): void {
        actingAsFinance();

        $rows = collect($this->getJson('/api/v1/reports/commission-eligibility')->assertOk()->json('data'));

        $expected = CommissionPool::query()->get()
            ->filter(fn (CommissionPool $p): bool => $p->isDistributable())
            ->count();

        expect($rows->where('status', 'Eligible')->count())->toBe($expected);
    });
});

// ---------------------------------------------------------------------------
// §7B, §7C — financial position
// ---------------------------------------------------------------------------

describe('balance sheet (§7B)', function (): void {
    /*
     * The equation is the report. Retained earnings is income less expense,
     * because nothing closes the books at year end and there is no
     * retained-earnings account — without it the sheet would not balance and a
     * reader would take that for a ledger bug.
     */
    it('balances', function (): void {
        actingAsFinance();

        $response = $this->getJson('/api/v1/reports/balance-sheet')->assertOk();

        expect($response->json('meta.totals.amount'))->toBe('0.00')
            ->and($response->json('meta.totals.account'))->toBe('Assets = Liabilities + Equity');

        $summary = collect($response->json('meta.summary'));
        expect($summary->firstWhere('label', 'Balanced')['value'])->toBe('Yes');
    });

    it('carries every section, control accounts included', function (): void {
        actingAsFinance();

        $sections = collect($this->getJson('/api/v1/reports/balance-sheet')->assertOk()->json('data'))
            ->pluck('section')->unique();

        // Dropping control accounts would break the equation, because they
        // carry real balances.
        expect($sections)->toContain('Assets', 'Liabilities', 'Equity', 'Control Accounts');
    });

    it('agrees with the trial balance on total assets', function (): void {
        actingAsFinance();

        $assets = app(ReportSources::class)->balanceOfType(new ReportFilters, AccountType::Asset);

        $summary = collect($this->getJson('/api/v1/reports/balance-sheet')->assertOk()->json('meta.summary'));

        expect($summary->firstWhere('label', 'Total Assets')['value'])->toBe($assets->toDecimalString());
    });
});

describe('cash position (§7C)', function (): void {
    /*
     * The distinction this report exists to draw: a loan receivable is a real
     * asset and is not money that can be spent this afternoon.
     */
    it('separates cash from receivables', function (): void {
        actingAsFinance();

        $response = $this->getJson('/api/v1/reports/cash-position')->assertOk();
        $summary = collect($response->json('meta.summary'));

        $cash = Money::of($summary->firstWhere('label', 'Available Cash')['value']);
        $receivables = Money::of($summary->firstWhere('label', 'Receivables')['value']);
        $assets = app(ReportSources::class)->balanceOfType(new ReportFilters, AccountType::Asset);

        expect($receivables->isPositive())->toBeTrue()
            // Cash and receivables together are the asset side; neither alone.
            ->and($cash->add($receivables)->toDecimalString())->toBe($assets->toDecimalString());
    });

    it('states headroom as cash less obligations', function (): void {
        actingAsFinance();

        $summary = collect($this->getJson('/api/v1/reports/cash-position')->assertOk()->json('meta.summary'));

        $cash = Money::of($summary->firstWhere('label', 'Available Cash')['value']);
        $obligations = Money::of($summary->firstWhere('label', 'Obligations')['value']);
        $headroom = Money::of($summary->firstWhere('label', 'Headroom')['value']);

        expect($headroom->toDecimalString())->toBe($cash->subtract($obligations)->toDecimalString());
    });
});

// ---------------------------------------------------------------------------
// §9C, §10B, §10C — daily position and the strategic pair
// ---------------------------------------------------------------------------

describe('daily position (§9C)', function (): void {
    it('runs a balance down the page', function (): void {
        actingAsFinance();

        $rows = $this->getJson('/api/v1/reports/daily-position')->assertOk()->json('data');

        expect($rows)->not->toBeEmpty();

        // Each balance is the one before it plus that day's net.
        for ($i = 1; $i < count($rows); $i++) {
            $expected = Money::of((string) $rows[$i - 1]['balance'])
                ->add(Money::of((string) $rows[$i]['net']));

            expect($rows[$i]['balance'])->toBe($expected->toDecimalString());
        }
    });

    it('closes where cash position says the cash is', function (): void {
        actingAsFinance();

        $closing = collect($this->getJson('/api/v1/reports/daily-position')->assertOk()->json('meta.summary'))
            ->firstWhere('label', 'Closing Balance')['value'];

        $cash = collect($this->getJson('/api/v1/reports/cash-position')->assertOk()->json('meta.summary'))
            ->firstWhere('label', 'Available Cash')['value'];

        expect($closing)->toBe($cash);
    });

    it('states net as cash in less cash out', function (): void {
        actingAsFinance();

        foreach ($this->getJson('/api/v1/reports/daily-position')->assertOk()->json('data') as $row) {
            $net = Money::of((string) $row['cashIn'])->subtract(Money::of((string) $row['cashOut']));

            expect($row['net'])->toBe($net->toDecimalString());
        }
    });
});

describe('growth (§10B)', function (): void {
    it('reports month by month with the change on the month before', function (): void {
        actingAsFinance();

        $rows = $this->getJson('/api/v1/reports/growth')->assertOk()->json('data');

        expect($rows)->not->toBeEmpty()
            ->and($rows[0])->toHaveKeys([
                'month', 'loans', 'disbursed', 'disbursedGrowth', 'customers', 'collected',
            ]);

        // Newest first, so the earliest month is last and has no comparison.
        expect(end($rows)['disbursedGrowth'])->toBe('—');
    });

    it('counts a loan in the month it was disbursed', function (): void {
        actingAsFinance();

        $rows = collect($this->getJson('/api/v1/reports/growth')->assertOk()->json('data'));
        $counted = $rows->sum(fn (array $r): int => (int) $r['loans']);

        $disbursed = App\Models\Loan::query()->whereNotNull('disbursement_date')->count();

        expect($counted)->toBe($disbursed);
    });
});

describe('risk (§10C)', function (): void {
    it('flags default and expense in one row per branch', function (): void {
        actingAsFinance();

        $rows = $this->getJson('/api/v1/reports/risk')->assertOk()->json('data');

        expect($rows)->not->toBeEmpty()
            ->and($rows[0])->toHaveKeys(['branch', 'par', 'costRatio', 'flags']);
    });

    it('orders worst first', function (): void {
        actingAsFinance();

        $par = array_map(
            static fn (array $r): string => (string) $r['par'],
            $this->getJson('/api/v1/reports/risk')->assertOk()->json('data'),
        );

        for ($i = 1; $i < count($par); $i++) {
            expect(bccomp($par[$i - 1], $par[$i], 3))->toBeGreaterThanOrEqual(0);
        }
    });

    it('agrees with branch P&L on income and expense', function (): void {
        actingAsFinance();

        $risk = collect($this->getJson('/api/v1/reports/risk')->assertOk()->json('data'))->keyBy('branch');
        $pnl = collect($this->getJson('/api/v1/reports/branch-pnl')->assertOk()->json('data'))->keyBy('branch');

        foreach ($pnl as $branch => $row) {
            expect($risk[$branch]['income'])->toBe($row['income'])
                ->and($risk[$branch]['expense'])->toBe($row['expense']);
        }
    });
});

// ---------------------------------------------------------------------------
// Permissions and branch scope apply to the new reports too
// ---------------------------------------------------------------------------

it('refuses every new report without reports.view', function (): void {
    actingAsRole(RoleName::Teller);

    foreach ([
        'branch-expense', 'hq-expense', 'hq-allocation', 'profit-adjustment',
        'commission-eligibility', 'balance-sheet', 'cash-position', 'daily-position',
        'growth', 'risk',
    ] as $slug) {
        $this->getJson("/api/v1/reports/{$slug}")->assertForbidden();
    }
});

/*
 * §13 — a user without cross-branch visibility is pinned to their own branch
 * regardless of what the query string asks for. A report must not be a way
 * around the scoping every other endpoint enforces.
 */
it('pins a branch-scoped report to the caller’s own branch', function (): void {
    $officer = officerAt('Kakonko', RoleName::BranchManager);
    $other = App\Models\Branch::query()->where('name', 'Missenyi')->value('id');

    $response = $this->getJson("/api/v1/reports/branch-expense?branch_id={$other}")->assertOk();

    expect($response->json('meta.filters_applied.branch_id'))->toBe((string) $officer->branch_id);
});
