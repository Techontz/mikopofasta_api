<?php

namespace App\Http\Controllers\Api\V1\Expenses;

use App\Enums\Account;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\JournalLine;
use App\Models\Loan;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Loan Fee → Deducted Income (live admin/deducted_income).
 */
class LoanFeeIncomeController extends ApiController
{
    /**
     * Positive LOAN FEE A/C ledger lines posted against loans (fee deducted at disbursement).
     * Defaults to today's income for all visible branches; the filter modal narrows by branch and dates.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorizeAny('income.view');

        $validated = $request->validate([
            'branch_id' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = CarbonImmutable::parse($validated['from'] ?? today());
        $to = CarbonImmutable::parse($validated['to'] ?? today());
        $branchId = $validated['branch_id'] ?? 'all';
        $visible = $this->visibleBranches()->modelKeys();

        if ($branchId !== 'all' && $branchId !== '') {
            $this->assertBranchAccessible((int) $branchId);
        }

        $rows = JournalLine::query()
            ->select('journal_lines.*')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('accounts.company_id', $this->currentEmployee()->company_id)
            ->where('accounts.key', Account::LoanFee->value)
            ->where('journal_lines.debit', '>', 0)
            ->where('journal_entries.source_type', (new Loan)->getMorphClass())
            ->whereDate('journal_entries.entry_date', '>=', $from->toDateString())
            ->whereDate('journal_entries.entry_date', '<=', $to->toDateString())
            ->whereIn('accounts.branch_id', $visible)
            ->when($branchId !== 'all' && $branchId !== '', fn ($query) => $query->where('accounts.branch_id', (int) $branchId))
            ->with(['account.branch', 'entry.source.customer'])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_lines.id')
            ->get()
            ->map(function (JournalLine $line): array {
                $loan = $line->entry->source instanceof Loan ? $line->entry->source : null;

                return [
                    'id' => $line->id,
                    'loan_id' => $loan?->id,
                    'customer' => $loan?->customer?->full_name,
                    'branch' => $line->account?->branch?->name,
                    'loan_approved' => (float) ($loan?->amount_approved ?? 0),
                    'amount' => (float) $line->debit,
                    'date' => $line->entry->entry_date?->toDateString(),
                ];
            });

        return response()->json(['data' => $rows, 'total' => round($rows->sum('amount'), 2)]);
    }
}
