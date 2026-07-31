<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Domain\Reports\Support\Cell;
use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use App\Support\Money;

/**
 * `GET /reports/cashflow` — movement across cash and bank accounts over the
 * selected window.
 *
 * Cash accounts are the non-system asset accounts: the 8xxx bank rows and the
 * per-branch Teller Cash rows (§5, §12). Identified by that rule rather than
 * by a hardcoded list of codes, so a bank account added tomorrow appears here
 * without a code change.
 */
final class CashflowReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'cashflow';
    }

    public function title(): string
    {
        return 'Cashflow';
    }

    public function description(): string
    {
        return 'Movement across cash and bank accounts over the selected window.';
    }

    public function group(): string
    {
        return 'Financial';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'from', 'to', 'period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $cashAccounts = ChartOfAccount::query()
            ->with('branch')
            ->where('type', AccountType::Asset)
            ->where('is_system', false)
            ->orderBy('code')
            ->get();

        $cashIds = $cashAccounts->pluck('id')->all();

        $lines = $this->sources->journalLines($filters)
            ->filter(fn (JournalEntryLine $l): bool => in_array($l->account_id, $cashIds, true));

        $rows = [];
        $totalIn = Money::zero();
        $totalOut = Money::zero();

        foreach ($cashAccounts as $account) {
            $own = $lines->where('account_id', $account->getKey());

            $inflow = Money::sum($own->map(fn (JournalEntryLine $l): Money => $l->debitAmount()));
            $outflow = Money::sum($own->map(fn (JournalEntryLine $l): Money => $l->creditAmount()));

            if (! $inflow->isPositive() && ! $outflow->isPositive()) {
                continue;
            }

            $rows[] = [
                'code' => $account->code,
                'account' => $account->name,
                'branch' => Cell::text($account->branch?->name),
                'inflow' => $inflow->toDecimalString(),
                'outflow' => $outflow->toDecimalString(),
                'net' => $inflow->subtract($outflow)->toDecimalString(),
            ];

            $totalIn = $totalIn->add($inflow);
            $totalOut = $totalOut->add($outflow);
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('code', 'Code'),
                ReportColumn::text('account', 'Account'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::money('inflow', 'Inflow'),
                ReportColumn::money('outflow', 'Outflow'),
                ReportColumn::money('net', 'Net'),
            ],
            rows: $rows,
            totals: [
                'code' => '',
                'account' => 'Total',
                'inflow' => $totalIn->toDecimalString(),
                'outflow' => $totalOut->toDecimalString(),
                'net' => $totalIn->subtract($totalOut)->toDecimalString(),
            ],
            summary: [
                ['label' => 'Cash In', 'value' => $totalIn->toDecimalString()],
                ['label' => 'Cash Out', 'value' => $totalOut->toDecimalString()],
                ['label' => 'Net Movement', 'value' => $totalIn->subtract($totalOut)->toDecimalString()],
            ],
            emptyMessage: 'No cash movement in this window.',
            reconciliation: 'Inflow and outflow are the debit and credit totals on bank and teller-cash accounts in the ledger — nothing here is summed from payments or disbursements independently.',
        );
    }
}
