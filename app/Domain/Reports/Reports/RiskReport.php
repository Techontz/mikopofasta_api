<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Models\Branch;
use App\Support\Money;

/**
 * `GET /reports/risk` — §10C of the reports document.
 *
 *   > High default branches · High expense branches
 *
 * Both in one row per branch, because they are the same question asked twice:
 * *which branches should somebody go and look at.* A branch that is only one
 * of the two is a different problem from a branch that is both, and two
 * separate reports would hide exactly that.
 *
 * ## The flags
 *
 * A branch is flagged when it is materially worse than the company as a whole,
 * not against a fixed threshold. The documents give no numbers, and a
 * hard-coded "PAR above 5%" would be this system asserting a risk appetite
 * nobody stated. Comparing against the company's own average is a statement
 * the data supports: *this branch is worse than the rest of you.*
 *
 * The multiplier is 1.5, and it is a presentation choice rather than a rule —
 * it decides which rows carry a flag, and every underlying figure is on the row
 * for a reader who disagrees with it.
 */
final class RiskReport implements Report
{
    /**
     * How much worse than the company average a branch must be to be flagged.
     *
     * Not a business rule. It exists so the flag column means something
     * consistent, and it is stated here rather than buried so that changing it
     * is a deliberate act.
     */
    private const string FLAG_MULTIPLIER = '1.5';

    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'risk';
    }

    public function title(): string
    {
        return 'Risk';
    }

    public function description(): string
    {
        return 'Branches carrying more arrears or more cost than the company average, and by how much.';
    }

    public function group(): string
    {
        return 'Portfolio';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'from', 'to'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $loans = $this->sources->openBookLoans($filters);

        /** @var array<int, array{outstanding: Money, overdue: Money, loans: int, defaulted: int}> $byBranch */
        $byBranch = [];

        foreach ($loans as $loan) {
            $id = (int) $loan->branch_id;

            $byBranch[$id] ??= [
                'outstanding' => Money::zero(),
                'overdue' => Money::zero(),
                'loans' => 0,
                'defaulted' => 0,
            ];

            $byBranch[$id]['outstanding'] = $byBranch[$id]['outstanding']
                ->add($this->sources->loanOutstanding($loan));
            $byBranch[$id]['overdue'] = $byBranch[$id]['overdue']
                ->add($this->sources->loanOverdue($loan));
            $byBranch[$id]['loans']++;

            if ($this->sources->daysPastDue($loan) > 30) {
                $byBranch[$id]['defaulted']++;
            }
        }

        $companyOutstanding = Money::sum(array_map(
            static fn (array $f): Money => $f['outstanding'],
            array_values($byBranch),
        ));
        $companyOverdue = Money::sum(array_map(
            static fn (array $f): Money => $f['overdue'],
            array_values($byBranch),
        ));

        $companyPar = $this->ratio($companyOverdue, $companyOutstanding);

        // Income and expense per branch, so the cost side is the ledger's.
        $branches = $this->sources->branches($filters);
        $figures = [];
        $companyIncome = Money::zero();
        $companyExpense = Money::zero();

        foreach ($branches as $branch) {
            $trial = $this->sources->trialBalance($filters->forBranch((int) $branch->getKey()));

            $income = $this->sources->balanceOfTypeFrom($trial, AccountType::Income);
            $expense = $this->sources->balanceOfTypeFrom($trial, AccountType::Expense);

            $figures[(int) $branch->getKey()] = ['income' => $income, 'expense' => $expense];
            $companyIncome = $companyIncome->add($income);
            $companyExpense = $companyExpense->add($expense);
        }

        $companyCostRatio = $this->ratio($companyExpense, $companyIncome);

        $rows = [];
        $flagged = 0;

        foreach ($branches as $branch) {
            $id = (int) $branch->getKey();
            $book = $byBranch[$id] ?? null;
            $ledger = $figures[$id] ?? ['income' => Money::zero(), 'expense' => Money::zero()];

            $outstanding = $book['outstanding'] ?? Money::zero();
            $overdue = $book['overdue'] ?? Money::zero();

            $par = $this->ratio($overdue, $outstanding);
            $costRatio = $this->ratio($ledger['expense'], $ledger['income']);

            $highDefault = $this->exceeds($par, $companyPar);
            $highExpense = $this->exceeds($costRatio, $companyCostRatio);

            if ($highDefault || $highExpense) {
                $flagged++;
            }

            $rows[] = [
                'branch' => $branch->name,
                'loans' => (string) ($book['loans'] ?? 0),
                'outstanding' => $outstanding->toDecimalString(),
                'overdue' => $overdue->toDecimalString(),
                'par' => $par,
                'defaulted' => (string) ($book['defaulted'] ?? 0),
                'income' => $ledger['income']->toDecimalString(),
                'expense' => $ledger['expense']->toDecimalString(),
                'costRatio' => $costRatio,
                'flags' => $this->flags($highDefault, $highExpense),
            ];
        }

        // Worst first — a risk report nobody has to sort is one people read.
        usort($rows, static fn (array $a, array $b): int => bccomp(
            (string) $b['par'],
            (string) $a['par'],
            3,
        ));

        return new ReportResult(
            columns: [
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::number('loans', 'Loans'),
                ReportColumn::money('outstanding', 'Outstanding'),
                ReportColumn::money('overdue', 'Overdue'),
                ReportColumn::percent('par', 'PAR'),
                ReportColumn::number('defaulted', 'Over 30 DPD'),
                ReportColumn::money('income', 'Income'),
                ReportColumn::money('expense', 'Expense'),
                ReportColumn::percent('costRatio', 'Cost Ratio'),
                ReportColumn::text('flags', 'Flags'),
            ],
            rows: $rows,
            totals: [
                'branch' => sprintf('%d branches', count($rows)),
                'outstanding' => $companyOutstanding->toDecimalString(),
                'overdue' => $companyOverdue->toDecimalString(),
                'par' => $companyPar,
                'income' => $companyIncome->toDecimalString(),
                'expense' => $companyExpense->toDecimalString(),
                'costRatio' => $companyCostRatio,
            ],
            summary: [
                ['label' => 'Company PAR', 'value' => $companyPar.'%'],
                ['label' => 'Company Cost Ratio', 'value' => $companyCostRatio.'%'],
                ['label' => 'Branches Flagged', 'value' => (string) $flagged],
            ],
            emptyMessage: 'No branches match these filters.',
            reconciliation: 'PAR is overdue over outstanding on the open loan book, the same two figures the Arrears report shows. Cost Ratio is ledger expense over ledger income for the branch, the same pair as Branch P&L. A branch is flagged when it is more than 1.5x the company figure — a comparison the data supports, rather than a fixed threshold this system has no mandate to set. Every underlying number is on the row, so a reader who disagrees with the multiplier can apply their own.',
        );
    }

    private function ratio(Money $part, Money $whole): string
    {
        return $whole->isPositive() ? $this->sources->percentageOf($part, $whole) : '0.000';
    }

    private function exceeds(string $branch, string $company): bool
    {
        if (bccomp($company, '0', 3) <= 0) {
            // Nothing to be worse than: if the company has no arrears at all,
            // any arrears at a branch is the whole of them.
            return bccomp($branch, '0', 3) > 0;
        }

        return bccomp($branch, bcmul($company, self::FLAG_MULTIPLIER, 3), 3) > 0;
    }

    private function flags(bool $highDefault, bool $highExpense): string
    {
        $flags = array_filter([
            $highDefault ? 'High default' : null,
            $highExpense ? 'High expense' : null,
        ]);

        return $flags === [] ? '—' : implode(' · ', $flags);
    }
}
