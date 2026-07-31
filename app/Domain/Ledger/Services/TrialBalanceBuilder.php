<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Services;

use App\Models\ChartOfAccount;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The trial balance — a derived read-model, mirroring the frontend's
 * `buildTrialBalance`.
 *
 * Computed from `journal_entry_lines` every time, NOT read from
 * `account_balances`. §2.7 calls that table a materialized cache; deriving the
 * trial balance from the cache would mean the report that is supposed to PROVE
 * the books balance was reading a summary it cannot verify. Recomputing is what
 * makes it evidence rather than an echo.
 */
final class TrialBalanceBuilder
{
    public function __construct(private readonly AccountResolver $accounts) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     totalDebits: string,
     *     totalCredits: string,
     *     balanced: bool
     * }
     */
    public function build(?int $branchId = null, ?string $upToDate = null): array
    {
        $totals = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->select('jel.account_id')
            ->selectRaw('SUM(jel.debit_amount) AS debit_total, SUM(jel.credit_amount) AS credit_total')
            ->when($branchId !== null, fn ($q) => $q->where('jel.branch_id', $branchId))
            ->when($upToDate !== null, fn ($q) => $q->whereDate('je.entry_date', '<=', $upToDate))
            ->groupBy('jel.account_id')
            ->get()
            ->keyBy('account_id');

        $rows = [];
        $totalDebits = Money::zero();
        $totalCredits = Money::zero();

        $accounts = ChartOfAccount::query()->orderBy('code')->get();

        foreach ($accounts as $account) {
            $row = $totals->get($account->getKey());

            $debit = Money::of((string) ($row->debit_total ?? '0.00'));
            $credit = Money::of((string) ($row->credit_total ?? '0.00'));

            $totalDebits = $totalDebits->add($debit);
            $totalCredits = $totalCredits->add($credit);

            $rows[] = [
                'accountId' => (string) $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'isSystem' => $account->is_system,
                'branchId' => $account->branch_id === null ? null : (string) $account->branch_id,
                'debitTotal' => $debit->toDecimalString(),
                'creditTotal' => $credit->toDecimalString(),
                'balance' => $this->accounts->netBalance($account->type, $debit, $credit)->toDecimalString(),
            ];
        }

        return [
            'rows' => $rows,
            'totalDebits' => $totalDebits->toDecimalString(),
            'totalCredits' => $totalCredits->toDecimalString(),

            // Exact, not within a tolerance. Integer minor units leave no
            // rounding noise, so anything other than equality is a real defect.
            'balanced' => $totalDebits->equals($totalCredits),
        ];
    }

    /**
     * A running-balance view of one account's own lines, oldest first — what
     * the account-detail screen shows. Mirrors the frontend's
     * `buildAccountLedger`.
     *
     * @return list<array<string, mixed>>
     */
    public function accountLedger(ChartOfAccount $account, ?int $branchId = null): array
    {
        $lines = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('jel.account_id', $account->getKey())
            ->when($branchId !== null, fn ($q) => $q->where('jel.branch_id', $branchId))
            ->orderBy('je.posted_at')
            ->orderBy('jel.id')
            ->select(
                'jel.id',
                'jel.debit_amount',
                'jel.credit_amount',
                'je.id as entry_id',
                'je.entry_number',
                'je.entry_date',
                'je.description',
                'je.is_reversal',
            )
            ->get();

        $running = Money::zero();
        $rows = [];

        foreach ($lines as $line) {
            $debit = Money::of((string) $line->debit_amount);
            $credit = Money::of((string) $line->credit_amount);

            // Accumulated on the account's normal side, so the figure reads
            // the way an accountant expects rather than as a raw Dr−Cr.
            $running = $running->add($this->accounts->netBalance($account->type, $debit, $credit));

            $rows[] = [
                'id' => (string) $line->id,
                'entryId' => (string) $line->entry_id,
                'entryNumber' => $line->entry_number,
                'entryDate' => $line->entry_date,
                'description' => $line->description,
                'debit' => $debit->toDecimalString(),
                'credit' => $credit->toDecimalString(),
                'runningBalance' => $running->toDecimalString(),
                'isReversal' => (bool) $line->is_reversal,
            ];
        }

        return $rows;
    }
}
