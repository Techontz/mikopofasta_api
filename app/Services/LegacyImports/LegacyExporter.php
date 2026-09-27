<?php

namespace App\Services\LegacyImports;

use App\Enums\LoanStatus;
use App\Models\LegacyImport;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Penalty;
use App\Models\SalaryAdvance;
use App\Services\DashboardStatistics;
use App\Services\Reports\LoanBalances;

/**
 * Export File: the three lists written out in exactly the columns the import reads, so an exported file can be checked,
 * corrected and uploaded again (it is then recognised as already imported, never added twice).
 *
 * Every export covers all the given branches in one file, grouped by branch; each row's Branch Name says where it belongs.
 *
 *  - Active Salary Advance: the Active Salary Advance list.
 *  - Penalty List: the unpaid penalties, each at what is still owed.
 *  - Loan File (Active/Default + year): the loans of that status withdrawn in or before the year. Loan
 *    Amount is what the customer owes in all (principal + interest), Collection the amount due per instalment, Paid and
 *    Remain leave penalties out, and January–September are the loan repayments of each month of the year (penalty parts
 *    left out), with the old system's printed history for a loan imported with that year's file.
 */
final class LegacyExporter
{
    /**
     * @param  list<int>  $branchIds
     * @return list<list<string>>
     */
    public function lines(string $module, array $branchIds, ?string $loanStatus = null, ?int $year = null): array
    {
        $rows = match ($module) {
            LegacyImport::MODULE_SALARY_ADVANCE => $this->salaryAdvances($branchIds),
            LegacyImport::MODULE_PENALTY => $this->penalties($branchIds),
            LegacyImport::MODULE_LOAN => $this->loans($branchIds, $loanStatus ?? 'Active', $year ?? (int) now()->year),
        };

        $lines = [LegacyFileFormat::headers($module)];
        foreach (array_values($rows) as $index => $row) {
            $lines[] = [(string) ($index + 1), ...$row];
        }

        return $lines;
    }

    public function fileName(string $module, ?string $loanStatus = null, ?int $year = null): string
    {
        $parts = [LegacyFileFormat::label($module), ...($module === LegacyImport::MODULE_LOAN ? [$loanStatus, $year] : []), now()->format('Y-m-d')];

        return preg_replace('/[^A-Za-z0-9._-]+/', '-', implode(' ', array_filter($parts))).'.xlsx';
    }

    /**
     * @param  list<int>  $branchIds
     * @return list<list<string>>
     */
    private function salaryAdvances(array $branchIds): array
    {
        return SalaryAdvance::query()
            ->whereIn('branch_id', $branchIds ?: [0])
            ->where('status', 'active')
            ->whereNull('reversed_at')
            ->with(['customer', 'branch'])
            ->withSum('payments', 'amount')
            ->orderBy('branch_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (SalaryAdvance $advance): array => [
                LegacyRowReader::cleanName((string) $advance->customer?->full_name),
                (string) $advance->branch?->name,
                self::amount($advance->amount),
                self::amount((float) $advance->total_payable - (float) $advance->amount),
                self::amount($advance->total_payable),
                self::amount($advance->paid_amount),
                self::amount($advance->remaining_amount),
                'Active',
                self::amount($advance->fee),
                (string) ($advance->approved_at ?? $advance->created_at)?->toDateString(),
                DashboardStatistics::repaymentCycleEnded($advance) ? 'OLD' : 'NEW',
            ])
            ->all();
    }

    /**
     * @param  list<int>  $branchIds
     * @return list<list<string>>
     */
    private function penalties(array $branchIds): array
    {
        return Penalty::query()
            ->whereIn('branch_id', $branchIds ?: [0])
            ->where('is_waived', false)
            ->whereColumn('paid_amount', '<', 'amount')
            ->with(['customer', 'branch', 'loan', 'legacyImportRow:id,loan_amount'])
            ->orderBy('branch_id')
            ->orderBy('penalty_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Penalty $penalty): array => [
                LegacyRowReader::cleanName((string) $penalty->customer?->full_name),
                (string) $penalty->branch?->name,
                self::amount($penalty->loan?->total_payable ?? $penalty->legacyImportRow?->loan_amount ?? 0),
                self::amount((float) $penalty->amount - (float) $penalty->paid_amount),
                $penalty->penalty_date->toDateString(),
                match (true) {
                    $penalty->is_legacy_opening => 'Old system',
                    $penalty->accrual_journal_entry_id !== null => 'Accrued',
                    default => 'Cash basis',
                },
            ])
            ->all();
    }

    /**
     * @param  list<int>  $branchIds
     * @return list<list<string>>
     */
    private function loans(array $branchIds, string $loanStatus, int $year): array
    {
        $statuses = $loanStatus === 'Default' ? [LoanStatus::Default] : [LoanStatus::Active, LoanStatus::Overdue];

        $loans = LoanBalances::join(Loan::query()
            ->whereIn('loans.branch_id', $branchIds ?: [0])
            ->whereIn('loans.status', LoanStatus::values(...$statuses))
            ->whereNotNull('loans.withdrawn_at')
            ->whereDate('loans.withdrawn_at', '<=', "{$year}-12-31"))
            ->with(['customer', 'branch', 'legacyImportRow.import:id,year'])
            ->orderBy('loans.branch_id')
            ->orderBy('loans.withdrawn_at')
            ->orderBy('loans.id')
            ->get();

        $monthly = [];
        LoanTransaction::query()
            ->whereIn('loan_id', $loans->modelKeys() ?: [0])
            ->where('type', 'deposit')
            ->whereNull('reversed_at')
            ->whereBetween('transaction_date', ["{$year}-01-01", "{$year}-12-31"])
            ->get(['loan_id', 'amount', 'penalty', 'transaction_date'])
            ->each(function (LoanTransaction $deposit) use (&$monthly): void {
                $month = (int) $deposit->transaction_date->format('n');
                $monthly[$deposit->loan_id][$month] = ($monthly[$deposit->loan_id][$month] ?? 0) + (float) $deposit->amount - (float) $deposit->penalty;
            });

        return $loans->map(function (Loan $loan) use ($monthly, $year, $loanStatus): array {
            $history = $loan->legacyImportRow?->import?->year === $year ? ($loan->legacyImportRow->monthly ?? []) : [];
            // A loan from the old system shows the Paid Amount its file printed (its Remain Amount can carry an old fee, so
            // Loan Amount − Remain is not always what was paid), plus what has been repaid here since.
            $paid = ($loan->is_legacy_opening ? (float) ($loan->legacyImportRow?->paid_amount ?? $loan->opening_paid_principal) : 0.0)
                + (float) $loan->paid_total - (float) $loan->paid_penalty;
            $remain = (float) $loan->out_total - (float) $loan->out_penalty;

            return [
                (string) $loan->branch?->name,
                LegacyRowReader::cleanName((string) $loan->customer?->full_name),
                (string) ($loan->customer?->phone ?? $loan->customer?->alternative_phone ?? ''),
                self::amount((float) $loan->total_payable + (float) $loan->insurance),
                $loan->duration->label().' / '.$loan->sessions,
                self::amount($loan->restoration > 0 ? $loan->restoration : $loan->instalment),
                self::amount($paid),
                self::amount($remain),
                (string) $loan->withdrawn_at?->toDateString(),
                $loanStatus,
                ...array_map(fn (int $month): string => self::amount(round((float) ($history[$month] ?? 0) + ($monthly[$loan->id][$month] ?? 0), 2)), range(1, count(LegacyFileFormat::MONTHS))),
            ];
        })->all();
    }

    private static function amount(float|string|null $value): string
    {
        $amount = round((float) $value, 2);

        return abs($amount - round($amount)) < 0.005 ? (string) (int) round($amount) : number_format($amount, 2, '.', '');
    }
}
