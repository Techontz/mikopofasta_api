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
 * `GET /reports/balance-sheet` — §7B of the reports document.
 *
 *   > Assets: Loan portfolio, Cash · Liabilities: Staff fund, Payables ·
 *   > Equity: Capital, Retained earnings
 *
 * Deliberately a **statement**, not a listing. Financial Statements already
 * lists every account that has moved; this arranges the same balances into the
 * three sections a balance sheet has, with a subtotal for each and the
 * accounting equation checked at the bottom. Those are different questions —
 * "what is in each account" and "does the business balance" — and folding them
 * into one table answers neither well.
 *
 * ## Retained earnings, and why it is computed rather than read
 *
 * There is no retained-earnings account in the chart, because nothing closes
 * the books at year end. Income less expense **is** the retained earnings to
 * date, and stating it as such is what makes the equation hold:
 *
 *     Assets = Liabilities + Equity + (Income − Expense)
 *
 * Presenting equity without it would show a balance sheet that does not
 * balance, which a reader would rightly take as a bug in the ledger rather
 * than an omission in the report.
 *
 * ## Control accounts
 *
 * §5's control accounts — Reserve, Arrears, Suspense, Offset — are neither
 * asset nor liability in the ordinary sense, and dropping them would break the
 * equation because they carry real balances. They are shown in their own
 * section, so the sheet is complete and the reader can see what they are.
 */
final class BalanceSheetReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'balance-sheet';
    }

    public function title(): string
    {
        return 'Balance Sheet';
    }

    public function description(): string
    {
        return 'Assets, liabilities and equity as at a date, with the accounting equation checked.';
    }

    public function group(): string
    {
        return 'Financial';
    }

    /**
     * `to` and nothing else on the date side: a balance sheet is a position at
     * a moment, not over a window. A "from" would imply otherwise.
     */
    public function supportedFilters(): array
    {
        return ['branchId', 'to'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $trial = $this->sources->trialBalance($filters);

        $sections = [
            ['Assets', AccountType::Asset],
            ['Liabilities', AccountType::Liability],
            ['Equity', AccountType::Equity],
            ['Control Accounts', AccountType::Control],
        ];

        $rows = [];
        $subtotals = [];

        foreach ($sections as [$label, $type]) {
            $accounts = collect($trial['rows'])
                ->filter(fn (array $r): bool => $r['type'] === $type->value)
                ->filter(fn (array $r): bool => Money::of((string) $r['balance'])->isPositive()
                    || Money::of((string) $r['balance'])->isNegative());

            foreach ($accounts as $account) {
                $rows[] = [
                    'section' => $label,
                    'code' => $account['code'],
                    'account' => $account['name'],
                    'amount' => $account['balance'],
                ];
            }

            $subtotal = $this->sources->balanceOfTypeFrom($trial, $type);
            $subtotals[$label] = $subtotal;

            $rows[] = [
                'section' => $label,
                'code' => '',
                'account' => 'Total '.$label,
                'amount' => $subtotal->toDecimalString(),
            ];
        }

        /*
         * Income less expense — earnings that have NOT been closed out.
         *
         * Deliberately still cumulative and still counting the close's own
         * entries, unlike every P&L-shaped read in the system. A balance sheet
         * is a position, not a period, and the close is a real movement of
         * equity that the position must reflect.
         *
         * What this figure means changed when Decision Register D1 introduced
         * the month-end close: it is now only what has accumulated since the
         * last close. Everything closed has already moved into the Profit
         * account (3100, an Equity account), so it is counted once in the Equity
         * subtotal above and must not be counted again here. The total is
         * unaffected either way — the close is a balanced entry — but the label
         * would otherwise claim earnings were retained when they had been
         * recognised.
         */
        $retained = $this->sources->balanceOfTypeFrom($trial, AccountType::Income)
            ->subtract($this->sources->balanceOfTypeFrom($trial, AccountType::Expense));

        $rows[] = [
            'section' => 'Equity',
            'code' => '',
            'account' => 'Current earnings, not yet closed (income less expense)',
            'amount' => $retained->toDecimalString(),
        ];

        $assets = $subtotals['Assets'];
        $liabilitiesEquity = $subtotals['Liabilities']
            ->add($subtotals['Equity'])
            ->add($retained)
            ->add($subtotals['Control Accounts']);

        $difference = $assets->subtract($liabilitiesEquity);

        return new ReportResult(
            columns: [
                ReportColumn::text('section', 'Section'),
                ReportColumn::text('code', 'Code'),
                ReportColumn::text('account', 'Account'),
                ReportColumn::money('amount', 'Amount'),
            ],
            rows: $rows,
            totals: [
                'section' => 'Equation',
                'account' => $difference->isZero() ? 'Assets = Liabilities + Equity' : 'OUT OF BALANCE',
                'amount' => $difference->toDecimalString(),
            ],
            summary: [
                ['label' => 'Total Assets', 'value' => $assets->toDecimalString()],
                ['label' => 'Total Liabilities', 'value' => $subtotals['Liabilities']->toDecimalString()],
                [
                    'label' => 'Equity + Retained',
                    'value' => $subtotals['Equity']->add($retained)->toDecimalString(),
                ],
                ['label' => 'Balanced', 'value' => $difference->isZero() ? 'Yes' : 'No'],
            ],
            emptyMessage: 'Nothing has been posted, so there is no position to state.',
            reconciliation: 'Balances come from TrialBalanceBuilder, which re-aggregates journal_entry_lines rather than reading the account_balances cache — a statement that proves the books balance must not read a summary it cannot verify. Retained earnings is income less expense, because nothing closes the books at year end and there is no retained-earnings account; without it the equation would not hold. Control accounts are shown in their own section rather than dropped, because they carry real balances and omitting them would break the equation.',
        );
    }
}
