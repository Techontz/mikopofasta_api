<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Models\Loan;
use App\Support\Money;

/**
 * `GET /reports/branch-ranking` — branches ranked by profit, with portfolio
 * and collection context.
 *
 * Ranked on profit rather than on origination volume, deliberately: a branch
 * that lends heavily and collects poorly is not the best branch, and ranking
 * on disbursement would say it was.
 */
final class BranchRankingReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'branch-ranking';
    }

    public function title(): string
    {
        return 'Branch Ranking';
    }

    public function description(): string
    {
        return 'Branches ranked by profit, with portfolio and collection context.';
    }

    public function group(): string
    {
        return 'Branch';
    }

    public function supportedFilters(): array
    {
        return ['from', 'to', 'period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $ranked = $this->sources->branches($filters->forBranch(null))
            ->map(function ($branch) use ($filters): array {
                $scoped = $filters->forBranch((int) $branch->getKey());
                $trial = $this->sources->trialBalance($scoped);

                $income = $this->sources->balanceOfTypeFrom($trial, AccountType::Income);
                $expense = $this->sources->balanceOfTypeFrom($trial, AccountType::Expense);

                $loans = $this->sources->openBookLoans($scoped);

                return [
                    'branch' => $branch->name,
                    'income' => $income,
                    'expense' => $expense,
                    'profit' => $income->subtract($expense),
                    'loans' => $loans->count(),
                    'outstanding' => Money::sum($loans->map(fn (Loan $l): Money => $this->sources->loanOutstanding($l))),
                ];
            })
            ->sortByDesc(fn (array $r): int => $r['profit']->minor)
            ->values();

        $rows = [];

        foreach ($ranked as $index => $row) {
            $rows[] = [
                'rank' => $index + 1,
                'branch' => $row['branch'],
                'income' => $row['income']->toDecimalString(),
                'expense' => $row['expense']->toDecimalString(),
                'profit' => $row['profit']->toDecimalString(),
                'loans' => $row['loans'],
                'outstanding' => $row['outstanding']->toDecimalString(),
            ];
        }

        return new ReportResult(
            columns: [
                ReportColumn::number('rank', 'Rank'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::money('income', 'Income'),
                ReportColumn::money('expense', 'Expense'),
                ReportColumn::money('profit', 'Profit'),
                ReportColumn::number('loans', 'Loans'),
                ReportColumn::money('outstanding', 'Outstanding'),
            ],
            rows: $rows,
            emptyMessage: 'No branches configured.',
            reconciliation: 'Ranking uses the same per-branch ledger figures as the Branch P&L report, and the same portfolio accessor as the Loan Portfolio report.',
        );
    }
}
