<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;

/**
 * `GET /reports/trial-balance` — every account with its debit total, credit
 * total and net balance.
 *
 * §15.6's list names Financial Statements but not a standalone trial balance;
 * Phase 8 asks for both. They are the same underlying computation — this one
 * is the raw ledger position, the other adds the balance-sheet and P&L
 * subtotals a statement needs. Both call TrialBalanceBuilder, so they cannot
 * disagree, and this one also exists at `GET /ledger/trial-balance` for the
 * Ledger screen (§15.4). One computation, three doors.
 */
final class TrialBalanceReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'trial-balance';
    }

    public function title(): string
    {
        return 'Trial Balance';
    }

    public function description(): string
    {
        return 'Every account with its debit total, credit total, and net balance on its normal side.';
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

        $rows = array_map(static fn (array $r): array => [
            'code' => $r['code'],
            'account' => $r['name'],
            'type' => $r['type'],

            // Populated only for the branch-scoped accounts §12 creates —
            // teller cash. Emitted because a reader looking at two identical
            // 'Teller Cash' rows needs to know which branch each belongs to.
            'branchId' => $r['branchId'],
            'debits' => $r['debitTotal'],
            'credits' => $r['creditTotal'],
            'balance' => $r['balance'],
        ], $trial['rows']);

        return new ReportResult(
            columns: [
                ReportColumn::text('code', 'Code'),
                ReportColumn::text('account', 'Account'),
                ReportColumn::text('type', 'Type'),
                ReportColumn::text('branchId', 'Branch'),
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
                ['label' => 'Total Debits', 'value' => $trial['totalDebits']],
                ['label' => 'Total Credits', 'value' => $trial['totalCredits']],
                ['label' => 'Balanced', 'value' => $trial['balanced'] ? 'Yes' : 'NO'],
            ],
            emptyMessage: 'No accounts have been posted to.',
            reconciliation: 'Recomputed from journal_entry_lines on every call, never read from the account_balances cache (§2.7). Balance is signed on each account\'s normal side, so an equity account with a net credit reads positive rather than negative.',
        );
    }
}
