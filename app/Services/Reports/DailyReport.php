<?php

namespace App\Services\Reports;

use App\Enums\Account;
use App\Models\AgentTransaction;
use App\Models\BankTransfer;
use App\Models\Capital;
use App\Models\ExpenseRequest;
use App\Models\FloatTransfer;
use App\Models\JournalLine;
use App\Models\LoanTransaction;
use App\Models\PenaltyPayment;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvancePayment;
use App\Models\Saving;
use App\Services\Reports\Financial\CashAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Daily Report" cash book.
 *
 * The live formulas are server-side only; they are inferred as follows:
 * - OPENING / CLOSING: the cash position from the ledger — the sum of every ledger entry in scope
 *   (the selected branches' accounts, or all branch and HQ accounts for ALL) on money accounts, excluding money
 *   held in bank accounts, receivables and the non-cash Offset / Outstanding Interest assets, up to the day before
 *   "from" (opening) and up to "to" (closing). Opening therefore always equals the previous day's closing.
 * - Money in: CAPITAL (share capital, HQ only), TRANSFER (approved float received), DEPOSIT (loan
 *   repayments less their penalty portion, which is reported under PENALTY), AGENT (agent transactions), SAVING DEPOSIT,
 *   DEBT PENDING (salary advance repayments), LOAN FEE (loan fee ledger inflow), PENALTY (penalty payments).
 * - Money out: LOAN WITHDRAWAL, SAVING WITHDRAWAL, DEBT PENDING (salary advances issued), EXPENSES
 *   (approved and paid expense requests by approval date, excluding those whose ledger posting was reversed), BANK (branch → bank transfers), TRANSFER (approved float sent).
 * Reversed records (reversal markers) are excluded.
 */
class DailyReport
{
    /**
     * Asset accounts that are not cash in hand: the non-money assets of {@see CashAccounts} plus bank accounts.
     *
     * @var list<Account>
     */
    public const NON_CASH_ASSETS = [...CashAccounts::NON_CASH_ASSETS, Account::Bank];

    /**
     * @return array{in: array<string, float>, out: array<string, float>, total_in: float, total_out: float, opening: float, closing: float}
     */
    public function build(ReportFilter $filter): array
    {
        return $this->forScope(new ReportScope($filter->company->id, $filter->branchId ? [$filter->branchId] : null), $filter->from, $filter->to);
    }

    /**
     * @return array{in: array<string, float>, out: array<string, float>, total_in: float, total_out: float, opening: float, closing: float}
     */
    public function forScope(ReportScope $scope, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $companyId = $scope->companyId;
        $branchIds = $scope->branchIds;
        $allBranches = $branchIds === null;
        $range = [$from->toDateString(), $to->toDateString().' 23:59:59'];
        $timestamps = [$from->startOfDay(), $to->endOfDay()];

        $scoped = function (Builder $query, string $column = 'branch_id') use ($companyId, $branchIds): Builder {
            $query->where($query->getModel()->qualifyColumn('company_id'), $companyId);
            if ($branchIds !== null) {
                $query->whereIn($query->getModel()->qualifyColumn($column), $branchIds ?: [0]);
            }

            return $query;
        };
        $notReversed = fn (Builder $query): Builder => $query->whereNull($query->getModel()->qualifyColumn('reversed_at'));
        $floats = fn (string $column): Builder => FloatTransfer::where('company_id', $companyId)->where('status', 'approved')->whereNull('reversed_at')
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn($column, $branchIds ?: [0]), fn (Builder $query) => $query->whereNotNull($column))
            ->whereBetween('transfer_date', $range);

        $in = [
            'CAPITAL' => $allBranches ? (float) Capital::where('company_id', $companyId)->active()->where('pay_method', '!=', 'ASSET')->whereBetween('created_at', $timestamps)->sum('amount') : 0.0,
            'TRANSFER' => (float) $floats('to_branch_id')->sum('amount'),
            'DEPOSIT' => (float) $notReversed($scoped(LoanTransaction::query()))->where('type', 'deposit')->whereBetween('transaction_date', $range)->sum(DB::raw('amount - penalty')),
            'AGENT' => (float) $notReversed($scoped(AgentTransaction::query()))->whereBetween('transaction_date', $range)->sum('amount'),
            'SAVING DEPOSIT' => (float) $notReversed($scoped(Saving::query()))->where('type', 'deposit')->whereBetween('transaction_date', $range)->sum('amount'),
            'DEBT PENDING' => (float) SalaryAdvancePayment::whereHas('salaryAdvance', fn (Builder $query) => $notReversed($scoped($query)))->whereNull('reversed_at')->whereBetween('paid_on', $range)->sum('amount'),
            'LOAN FEE' => $this->movement($companyId, $branchIds, Account::LoanFee, $from, $to),
            'PENALTY' => (float) PenaltyPayment::whereHas('penalty', fn (Builder $query) => $scoped($query))->whereBetween('paid_on', $range)->standing()
                ->where(fn (Builder $query) => $query->whereNull('loan_transaction_id')->orWhereIn('loan_transaction_id', LoanTransaction::query()->select('id')->whereNull('reversed_at')))
                ->sum('amount'),
        ];

        $out = [
            'LOAN WITHDRAWAL' => (float) $notReversed($scoped(LoanTransaction::query()))->where('type', 'withdrawal')->whereBetween('transaction_date', $range)->sum('amount'),
            'SAVING WITHDRAWAL' => (float) $notReversed($scoped(Saving::query()))->where('type', 'withdrawal')->whereBetween('transaction_date', $range)->sum('amount'),
            'DEBT PENDING' => (float) $notReversed($scoped(SalaryAdvance::query()))->whereNotNull('approved_at')->whereBetween('approved_at', $timestamps)->sum('amount'),
            'EXPENSES' => (float) $notReversed($scoped(ExpenseRequest::query()))->where('status', 'accepted')
                ->whereRaw('DATE(COALESCE(approved_at, request_date)) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
                ->whereDoesntHave('journalEntry.reversal')->sum('amount'),
            // Historic only: the branch → bank sweep was retired (a branch holds no money of its own beyond petty
            // cash), so this reports rows banked before that and is 0 from then on.
            'BANK' => (float) $notReversed($scoped(BankTransfer::query()))->where('type', 'branch_to_bank')->where('status', 'approved')->whereBetween('transfer_date', $range)->sum('amount'),
            'TRANSFER' => (float) $floats('from_branch_id')->sum('amount'),
        ];

        return [
            'in' => array_map(fn (float $value): float => round($value, 2), $in),
            'out' => array_map(fn (float $value): float => round($value, 2), $out),
            'total_in' => round(array_sum($in), 2),
            'total_out' => round(array_sum($out), 2),
            'opening' => $this->cashPosition($companyId, $branchIds, $from->subDay()),
            'closing' => $this->cashPosition($companyId, $branchIds, $to),
        ];
    }

    /**
     * Inflow (debit) movement of an account across the scoped branches.
     *
     * @param  list<int>|null  $branchIds
     */
    private function movement(int $companyId, ?array $branchIds, Account $account, CarbonImmutable $from, CarbonImmutable $to): float
    {
        return round((float) JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('accounts.company_id', $companyId)
            ->where('accounts.key', $account->value)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('accounts.branch_id', $branchIds ?: [0]))
            ->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->sum('journal_lines.'.($account->isDebitNormal() ? 'debit' : 'credit')), 2);
    }

    /**
     * @param  list<int>|null  $branchIds
     */
    private function cashPosition(int $companyId, ?array $branchIds, CarbonImmutable $until): float
    {
        $moneyAccounts = array_map(fn (Account $account): string => $account->value, CashAccounts::moneyAccounts(includeBank: false));

        $totals = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('accounts.company_id', $companyId)
            ->whereIn('accounts.key', $moneyAccounts)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('accounts.branch_id', $branchIds ?: [0]))
            ->whereDate('journal_entries.entry_date', '<=', $until->toDateString())
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) d, COALESCE(SUM(journal_lines.credit),0) c')
            ->first();

        return round((float) $totals->d - (float) $totals->c, 2);
    }
}
