<?php

namespace App\Console\Commands;

use App\Enums\Account;
use App\Enums\TransactionType;
use App\Models\Company;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Specification §11: pending / unverified receipts live in ONE central account at HQ, never in a pending account per
 * branch. Receipts recorded before that rule sit on branch-level suspense accounts; this moves each such balance to the
 * central account with an explicit, dated adjustment journal (§65: identified, traceable, nothing rewritten):
 *
 *     Dr SUSPENSE (branch) / Cr SUSPENSE (HQ)   — recorded for that branch, so its attribution is kept.
 *
 * Nothing about the receipts themselves changes: payments keep their branch, status and amounts. Safe to run again —
 * a branch account already at zero is skipped.
 */
class CentralisePendingReceipts extends Command
{
    protected $signature = 'mkopa:centralise-pending-receipts {--company= : Only this company id} {--dry-run : Show what would move without posting}';

    protected $description = 'Move branch-level suspense balances into the one central HQ pending / unverified receipts account';

    public function handle(Ledger $ledger): int
    {
        $companies = Company::query()
            ->when($this->option('company') !== null, fn ($query) => $query->whereKey((int) $this->option('company')))
            ->orderBy('id')->get();

        $today = CarbonImmutable::today();
        foreach ($companies as $company) {
            $balances = DB::table('journal_lines')
                ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                ->where('accounts.company_id', $company->id)
                ->where('accounts.key', Account::Suspense->value)
                ->whereNotNull('accounts.branch_id')
                ->groupBy('accounts.branch_id')
                ->selectRaw('accounts.branch_id, SUM(journal_lines.credit) - SUM(journal_lines.debit) AS balance')
                ->pluck('balance', 'branch_id');

            foreach ($balances as $branchId => $balance) {
                $amount = round((float) $balance, 2);
                if (abs($amount) < 0.005) {
                    continue;
                }

                $this->line(sprintf('%s: branch %d suspense %s → HQ%s', $company->name, $branchId, number_format($amount, 2), $this->option('dry-run') ? ' (dry run)' : ''));
                if ($this->option('dry-run')) {
                    continue;
                }

                // A credit balance (money held) moves as Dr branch / Cr HQ; an overdrawn branch account the other way.
                $ledger->journal($company, 'RECLASS PENDING RECEIPTS TO HQ (SPEC §11) BRANCH '.$branchId, [
                    ['account' => Account::Suspense, 'branch' => (int) $branchId, $amount > 0 ? 'debit' : 'credit' => abs($amount)],
                    ['account' => Account::Suspense, 'branch' => null, $amount > 0 ? 'credit' : 'debit' => abs($amount)],
                ], date: $today, branch: (int) $branchId, type: TransactionType::Adjustment);
            }
        }

        return self::SUCCESS;
    }
}
