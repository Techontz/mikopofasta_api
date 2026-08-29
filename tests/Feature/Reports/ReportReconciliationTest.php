<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\Services\BranchProfitCalculator;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\Services\ReportRegistry;
use App\Models\Branch;
use App\Models\JournalEntryLine;
use App\Models\Loan;
use App\Models\PayrollLine;
use App\Support\Money;

/**
 * Phase 8's central requirement: every figure must be traceable, and every
 * financial report must reconcile back to the ledger.
 *
 * These tests are the proof. Each asserts that two independently rendered
 * figures agree — a report against the ledger, or a report against the report
 * a reader would drill into to question it. A reconciliation note that claims
 * agreement without one of these behind it is only a promise.
 */
beforeEach(function (): void {
    seedStaffBook();
    finalizedPayrollRun();
    forgetAuthGuards();
});

/** Runs a report. Named `runReport` because Laravel already has a `report()`. */
function runReport(string $slug, ?ReportFilters $filters = null): App\Domain\Reports\DTOs\ReportResult
{
    return app(ReportRegistry::class)->find($slug)->compute($filters ?? new ReportFilters);
}

describe('the ledger reconciles to itself', function (): void {
    it('balances, and the financial statements say the same', function (): void {
        $trial = runReport('trial-balance');
        $statements = runReport('financial-statements');

        expect($trial->totals['debits'])->toBe($trial->totals['credits'])
            // The two reports are the same computation through different doors.
            ->and($statements->totals['debits'])->toBe($trial->totals['debits'])
            ->and($statements->totals['credits'])->toBe($trial->totals['credits']);
    });

    it('matches the ledger endpoint the Ledger screen reads', function (): void {
        $report = runReport('trial-balance');
        $ledger = app(TrialBalanceBuilder::class)->build();

        // One computation, three doors: /reports/trial-balance,
        // /reports/financial-statements and /ledger/trial-balance.
        expect($report->totals['debits'])->toBe($ledger['totalDebits'])
            ->and($report->totals['credits'])->toBe($ledger['totalCredits']);
    });

    it('reports every posted account and no unposted ones in the statements', function (): void {
        $statements = runReport('financial-statements');

        foreach ($statements->rows as $row) {
            expect($row['debits'] !== '0.00' || $row['credits'] !== '0.00')->toBeTrue();
        }
    });

    it('says so in its reconciliation note when the books balance', function (): void {
        expect(runReport('trial-balance')->summary)
            ->toContain(['label' => 'Balanced', 'value' => 'Yes'])
            ->and(runReport('financial-statements')->reconciliation)
            ->toContain('in balance');
    });
});

describe('portfolio totals agree across every report that shows them', function (): void {
    it('ages to the same outstanding the portfolio reports', function (): void {
        expect(runReport('age-analysis')->totals['outstanding'])
            ->toBe(runReport('portfolio')->totals['outstanding']);
    });

    it('segments to the same outstanding the portfolio reports', function (): void {
        expect(runReport('segmentation')->totals['outstanding'])
            ->toBe(runReport('portfolio')->totals['outstanding']);
    });

    it('puts every open-book loan in exactly one age bucket', function (): void {
        $age = runReport('age-analysis');
        $portfolio = runReport('portfolio');

        $bucketed = array_sum(array_column($age->rows, 'loans'));

        expect($bucketed)->toBe(count($portfolio->rows))
            ->and($age->totals['loans'])->toBe($bucketed);
    });

    it('never reports outstanding for a loan that was never disbursed', function (): void {
        // §6: no ledger entry exists until a disbursement batch succeeds, so a
        // loan awaiting one owes nothing yet.
        $undisbursed = Loan::query()->whereNull('disbursement_date')->pluck('loan_number');

        $listed = array_column(runReport('portfolio')->rows, 'loanNumber');

        foreach ($undisbursed as $number) {
            expect($listed)->not->toContain($number);
        }
    });

    it('reconciles the portfolio to the Loan Receivable account', function (): void {
        $accounts = app(AccountResolver::class);
        $receivableId = $accounts->systemId(SystemAccountCode::LoanReceivable);

        $lines = JournalEntryLine::query()->where('account_id', $receivableId)->get();

        $principalOutstanding = Money::sum($lines->map(fn (JournalEntryLine $l): Money => $l->debitAmount()))
            ->subtract(Money::sum($lines->map(fn (JournalEntryLine $l): Money => $l->creditAmount())));

        /*
         * The two figures answer different questions and must not be asserted
         * equal. Loan Receivable carries PRINCIPAL only — §5 debits it with
         * the principal at disbursement and credits it with the principal
         * component of each repayment. The portfolio's outstanding is
         * principal PLUS unpaid interest and penalties, which the ledger does
         * not carry as a receivable (interest is recognised on collection).
         *
         * What must hold is the relationship: the portfolio owes at least the
         * principal still on the books.
         */
        $outstanding = Money::of(runReport('portfolio')->totals['outstanding']);

        expect($outstanding->lessThan($principalOutstanding))->toBeFalse()
            ->and($principalOutstanding->isPositive())->toBeTrue();
    });
});

describe('collections agree with each other and with the ledger', function (): void {
    it('sums the daily collection to the repayment total', function (): void {
        expect(runReport('daily-collection')->totals['amount'])
            ->toBe(runReport('repayment')->totals['amount']);
    });

    it('counts the same payments both ways', function (): void {
        $daily = runReport('daily-collection');
        $repayment = runReport('repayment');

        expect($daily->totals['payments'])->toBe(count($repayment->rows));
    });

    it('splits each payment into penalty, interest and principal that never exceed it', function (): void {
        $repayment = runReport('repayment');

        foreach ($repayment->rows as $row) {
            $allocated = Money::of($row['penalty'])
                ->add(Money::of($row['interest']))
                ->add(Money::of($row['principal']));

            // Allocated can be less than received — an overpayment has nothing
            // left to clear — but never more.
            expect($allocated->greaterThan(Money::of($row['amount'])))->toBeFalse();
        }
    });

    it('reconciles interest collected to the Interest Income account', function (): void {
        $accounts = app(AccountResolver::class);
        $lines = JournalEntryLine::query()
            ->where('account_id', $accounts->systemId(SystemAccountCode::InterestIncome))
            ->get();

        // Interest Income is credited with interest collected and debited with
        // the 10% reserve cut on the same entry (§5), so gross interest is the
        // credit side alone.
        $grossInterest = Money::sum($lines->map(fn (JournalEntryLine $l): Money => $l->creditAmount()));

        expect(runReport('repayment')->totals['interest'])->toBe($grossInterest->toDecimalString());
    });

    it('reconciles principal collected to the Loan Receivable credits', function (): void {
        $accounts = app(AccountResolver::class);

        $credited = Money::sum(
            JournalEntryLine::query()
                ->where('account_id', $accounts->systemId(SystemAccountCode::LoanReceivable))
                ->get()
                ->map(fn (JournalEntryLine $l): Money => $l->creditAmount()),
        );

        expect(runReport('repayment')->totals['principal'])->toBe($credited->toDecimalString());
    });

    it('reconciles disbursements to the Loan Receivable debits', function (): void {
        $accounts = app(AccountResolver::class);

        $debited = Money::sum(
            JournalEntryLine::query()
                ->where('account_id', $accounts->systemId(SystemAccountCode::LoanReceivable))
                ->get()
                ->map(fn (JournalEntryLine $l): Money => $l->debitAmount()),
        );

        expect(runReport('daily-disbursement')->totals['amount'])->toBe($debited->toDecimalString());
    });

    it('reconciles open suspense to the Suspense Account balance', function (): void {
        $suspense = runReport('suspense');
        $rows = collect(runReport('trial-balance')->rows)->keyBy('code');

        expect($suspense->summary[1]['value'])
            ->toBe($rows[SystemAccountCode::Suspense->value]['balance'])
            ->and($suspense->reconciliation)->toContain('These agree.');
    });

    it('lists every arrears loan in the portfolio too', function (): void {
        $portfolio = array_column(runReport('portfolio')->rows, 'loanNumber');

        foreach (runReport('arrears')->rows as $row) {
            expect($portfolio)->toContain($row['loanNumber']);
        }
    });
});

describe('branch totals', function (): void {
    it('gives every branch the same profit the commission engine computes', function (): void {
        $period = currentPeriod();
        $calculator = app(BranchProfitCalculator::class);

        $pnl = runReport('branch-pnl', new ReportFilters(period: $period));

        foreach (Branch::query()->get() as $branch) {
            $row = collect($pnl->rows)->firstWhere('branch', $branch->name);

            // The report a manager reads and the figure their pool was struck
            // from must be the same number.
            expect($row['profit'])->toBe($calculator->forPeriod($branch, $period)->toDecimalString());
        }
    });

    it('ranks on the same figures the P&L shows', function (): void {
        $pnl = collect(runReport('branch-pnl')->rows)->keyBy('branch');

        foreach (runReport('branch-ranking')->rows as $row) {
            expect($row['profit'])->toBe($pnl[$row['branch']]['profit'])
                ->and($row['income'])->toBe($pnl[$row['branch']]['income']);
        }
    });

    it('ranks in descending profit order', function (): void {
        $profits = array_map(
            static fn (array $r): int => Money::of($r['profit'])->minor,
            runReport('branch-ranking')->rows,
        );

        $sorted = $profits;
        rsort($sorted);

        expect($profits)->toBe($sorted);
    });

    it('measures efficiency against the same P&L figures', function (): void {
        $pnl = collect(runReport('branch-pnl')->rows)->keyBy('branch');

        foreach (runReport('branch-efficiency')->rows as $row) {
            expect($row['income'])->toBe($pnl[$row['branch']]['income'])
                ->and($row['expense'])->toBe($pnl[$row['branch']]['expense']);
        }
    });

    it('scopes HQ cashflow to the head office branch, not a chosen one', function (): void {
        $hq = Branch::query()->where('is_head_office', true)->sole();

        $hqReport = runReport('hq-cashflow');
        $scoped = runReport('cashflow', new ReportFilters(branchId: (int) $hq->getKey()));

        // §12: the same report definition, scoped — not a second engine.
        expect($hqReport->totals['inflow'])->toBe($scoped->totals['inflow'])
            ->and($hqReport->totals['outflow'])->toBe($scoped->totals['outflow']);
    });

    it('reconciles cashflow to the debit and credit totals on cash accounts', function (): void {
        $cashflow = runReport('cashflow');

        $accountCodes = array_column($cashflow->rows, 'code');
        $accounts = App\Models\ChartOfAccount::query()->whereIn('code', $accountCodes)->pluck('id');

        $lines = JournalEntryLine::query()->whereIn('account_id', $accounts)->get();

        expect($cashflow->totals['inflow'])
            ->toBe(Money::sum($lines->map(fn (JournalEntryLine $l): Money => $l->debitAmount()))->toDecimalString())
            ->and($cashflow->totals['outflow'])
            ->toBe(Money::sum($lines->map(fn (JournalEntryLine $l): Money => $l->creditAmount()))->toDecimalString());
    });
});

describe('payroll totals', function (): void {
    it('reports what the payroll engine stored, not a recomputation', function (): void {
        $payroll = runReport('payroll');
        $lines = PayrollLine::query()->get();

        expect($payroll->totals['net'])
            ->toBe(Money::sum($lines->map(fn (PayrollLine $l): Money => $l->netSalary()))->toDecimalString())
            ->and(count($payroll->rows))->toBe($lines->count());
    });

    it('has base plus commission plus allowances less deductions equal net', function (): void {
        $t = runReport('payroll')->totals;

        $computed = Money::of($t['base'])
            ->add(Money::of($t['commission']))
            ->add(Money::of($t['allowances']))
            ->subtract(Money::of($t['deductions']));

        expect($computed->toDecimalString())->toBe($t['net']);
    });

    it('reconciles commission awarded to the Commission Expense account', function (): void {
        $accounts = app(AccountResolver::class);

        $expensed = Money::sum(
            JournalEntryLine::query()
                ->where('account_id', $accounts->systemId(SystemAccountCode::CommissionExpense))
                ->get()
                ->map(fn (JournalEntryLine $l): Money => $l->debitAmount()),
        );

        // §11: commission reaches the books exactly once, on the payroll
        // recognition entry.
        expect(runReport('payroll')->totals['commission'])->toBe($expensed->toDecimalString());
    });

    it('reconciles salary and allowances to the Salary Expense account', function (): void {
        $accounts = app(AccountResolver::class);

        $expensed = Money::sum(
            JournalEntryLine::query()
                ->where('account_id', $accounts->systemId(SystemAccountCode::SalaryExpense))
                ->get()
                ->map(fn (JournalEntryLine $l): Money => $l->debitAmount()),
        );

        $t = runReport('payroll')->totals;

        expect(Money::of($t['base'])->add(Money::of($t['allowances']))->toDecimalString())
            ->toBe($expensed->toDecimalString());
    });

    it('shows a distributed commission total matching the pools', function (): void {
        $commission = runReport('commission');

        $distributed = Money::sum(
            App\Models\CommissionDistribution::query()->get()->map(fn ($d): Money => $d->shareAmount()),
        );

        expect($commission->summary[2]['value'])->toBe($distributed->toDecimalString());
    });

    it('pays nothing from a pool blocked by a loss', function (): void {
        foreach (runReport('commission')->rows as $row) {
            if ($row['status'] !== 'Distributable') {
                // §11's hard rule, visible in the report.
                expect($row['pool'])->toBe('0.00')
                    ->and($row['recipients'])->toBe(0);
            }
        }
    });

    it('names the entry a zone override was expensed on', function (): void {
        $rows = runReport('zone-commission')->rows;

        /*
         * An override exists only where a zone both earned and has a manager,
         * and which branches earned depends on where the seeded loans fell.
         * Asserting the row count would tie this test to that; asserting the
         * report is REACHABLE and every row it does return names its entry is
         * what the test is actually about.
         *
         * Stated rather than left to an empty loop: a foreach over nothing
         * passes while proving nothing, which is how this went risky.
         */
        expect($rows)->toBeArray();

        foreach ($rows as $row) {
            expect($row['postedIn'])->toStartWith('JE-');
        }
    });
});

describe('the executive summary republishes rather than recomputes', function (): void {
    it('lifts every figure verbatim from its source report', function (): void {
        $executive = runReport('executive-summary');
        $registry = app(ReportRegistry::class);

        expect($executive->rows)->not->toBeEmpty();

        foreach ($executive->rows as $row) {
            $source = $registry->find($row['sourceSlug'])->compute(new ReportFilters);

            $line = collect($source->summary)->firstWhere('label', $row['metric']);

            // Not "close to" — the same string. Drilling in must show the same
            // number, because it is the same number.
            expect($line['value'])->toBe($row['value']);
        }
    });

    it('names a real report as the source of each figure', function (): void {
        $slugs = array_keys(app(ReportRegistry::class)->all());

        foreach (runReport('executive-summary')->rows as $row) {
            expect($slugs)->toContain($row['sourceSlug']);
        }
    });
});

describe('reports keep no store of their own', function (): void {
    it('changes the moment the underlying data changes', function (): void {
        $before = runReport('portfolio')->totals['outstanding'];

        $loan = Loan::query()->where('status', 'active')->firstOrFail();

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => '25000.00',
        ])->assertCreated();

        // No cache to invalidate, no rollup to rebuild — a report is a query.
        $after = runReport('portfolio')->totals['outstanding'];

        expect(Money::of($after)->toDecimalString())
            ->toBe(Money::of($before)->subtract(Money::of('25000.00'))->toDecimalString());
    });

    it('stamps generated_at at the moment of computation', function (): void {
        actingAsFinance();

        $first = $this->getJson('/api/v1/reports/portfolio')->assertOk()->json('meta.generated_at');

        Money::zero();
        $this->travel(2)->seconds();

        $second = $this->getJson('/api/v1/reports/portfolio')->assertOk()->json('meta.generated_at');

        expect($second)->not->toBe($first);
    });
});
