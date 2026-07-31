<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Support\Money;

/**
 * `GET /reports/financial-statements` — the trial balance by account, with
 * balance-sheet and P&L subtotals.
 *
 * This report and `/reports/trial-balance` are the same computation: both call
 * TrialBalanceBuilder, which re-aggregates `journal_entry_lines` on every call
 * rather than reading the `account_balances` cache. A statement that proves
 * the books balance must not be reading a summary it cannot itself verify.
 *
 * It never sums transactions independently. If a figure here disagreed with
 * the ledger, the ledger would be the one that is right — so it reads the
 * ledger directly and has nothing of its own to be wrong about.
 */
final class FinancialStatementsReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'financial-statements';
    }

    public function title(): string
    {
        return 'Financial Statements';
    }

    public function description(): string
    {
        return 'Trial balance by account, with balance-sheet and P&L subtotals.';
    }

    public function group(): string
    {
        return 'Financial';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'to'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $trial = $this->sources->trialBalance($filters);

        // Only accounts that have been posted to — a chart of 25 rows of zeros
        // is not a financial statement.
        $rows = collect($trial['rows'])
            ->filter(fn (array $r): bool => $r['debitTotal'] !== '0.00' || $r['creditTotal'] !== '0.00')
            ->map(fn (array $r): array => [
                'code' => $r['code'],
                'account' => $r['name'],
                'type' => AccountType::from((string) $r['type'])->label(),
                'debits' => $r['debitTotal'],
                'credits' => $r['creditTotal'],
                'balance' => $r['balance'],
            ])
            ->values()
            ->all();

        $of = fn (AccountType $t): Money => $this->sources->balanceOfTypeFrom($trial, $t);

        $assets = $of(AccountType::Asset);
        $liabilities = $of(AccountType::Liability);
        $equity = $of(AccountType::Equity);
        $income = $of(AccountType::Income);
        $expense = $of(AccountType::Expense);

        return new ReportResult(
            columns: [
                ReportColumn::text('code', 'Code'),
                ReportColumn::text('account', 'Account'),
                ReportColumn::text('type', 'Type'),
                ReportColumn::money('debits', 'Debits'),
                ReportColumn::money('credits', 'Credits'),
                ReportColumn::money('balance', 'Balance'),
            ],
            rows: $rows,
            totals: [
                'code' => '',
                'account' => 'Total',
                'debits' => $trial['totalDebits'],
                'credits' => $trial['totalCredits'],
            ],
            summary: [
                ['label' => 'Assets', 'value' => $assets->toDecimalString()],
                ['label' => 'Liabilities', 'value' => $liabilities->toDecimalString()],
                ['label' => 'Equity', 'value' => $equity->toDecimalString()],
                ['label' => 'Un-closed Income', 'value' => $income->toDecimalString()],
                ['label' => 'Un-closed Expense', 'value' => $expense->toDecimalString()],
                ['label' => 'Un-closed Result', 'value' => $income->subtract($expense)->toDecimalString()],
            ],
            emptyMessage: 'No postings for these filters.',
            reconciliation: $this->reconciliationNote($trial, $income->subtract($expense)),
        );
    }

    /**
     * @param array{totalDebits: string, totalCredits: string, balanced: bool, rows: list<array<string, mixed>>} $trial
     */
    private function reconciliationNote(array $trial, Money $unswept): string
    {
        $balance = $trial['balanced']
            ? sprintf('Trial balance is in balance — debits and credits both total %s.', $trial['totalDebits'])
            : sprintf(
                'OUT OF BALANCE by %s — investigate before relying on any figure here.',
                Money::of($trial['totalDebits'])->subtract(Money::of($trial['totalCredits']))->toDecimalString(),
            );

        if ($unswept->isZero()) {
            return $balance;
        }

        /*
         * Income and expense recognised since the last month-end close are not
         * a profit or a loss — they are simply un-closed. §8's close job sweeps
         * them into the Profit Account, and that job is not yet built (see the
         * Phase 6 notes), so this line will always be non-zero for now. Saying
         * so is the difference between a reader trusting the statement and
         * hunting for a discrepancy that does not exist.
         */
        return $balance.sprintf(
            ' %s of income net of expense has been recognised since the last month-end close and is not yet swept into the Profit Account — un-closed activity, not a result.',
            $unswept->toDecimalString(),
        );
    }
}
