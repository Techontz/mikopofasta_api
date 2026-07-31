<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Services;

use App\Domain\Ledger\Enums\AccountType;
use App\Enums\ActiveStatus;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\CompanyProfile;
use App\Models\ExpenseCategory;
use RuntimeException;

/**
 * The chart accounts an expense needs.
 *
 * Two jobs, both of which exist so no caller hardcodes an account: minting the
 * 6xxx account a new category owns, and naming the branch a headquarters cost
 * is booked against.
 */
final class ExpenseAccountResolver
{
    /**
     * Codes for dynamic expense accounts.
     *
     * 6000 and 6100 are taken by SystemAccountCode (Salary Expense, Commission
     * Expense), so dynamic ones start at 6200 — which is where the frontend's
     * own chart puts them too.
     *
     * The suffix is an allocated sequence, derived from the highest existing
     * one exactly as LedgerService derives the next journal entry number. It is
     * not the category's id: the account has to be created before the category
     * that points at it, since `chart_account_id` is NOT NULL, and a code
     * derived from a row that does not exist yet cannot be written.
     */
    private const DYNAMIC_EXPENSE_PREFIX = '6200';

    /**
     * Creates the ledger account a category will own.
     *
     * Called once, immediately before the category itself and inside the same
     * transaction — so a category either exists with its account or does not
     * exist at all.
     *
     * The account is deliberately not branch-scoped: one "Rent" account carries
     * every branch's rent, and the branch dimension on each journal line is
     * what separates them. That is what lets the Branch Expense Report filter
     * by branch while the consolidated P&L reads a single account rather than
     * summing one per branch.
     */
    public function createAccountFor(string $name): ChartOfAccount
    {
        return ChartOfAccount::query()->create([
            'code' => $this->nextAccountCode(),
            'name' => $this->accountName($name),
            'type' => AccountType::Expense,
            'is_system' => false,
            'branch_id' => null,
            'status' => ActiveStatus::Active,
        ]);
    }

    /**
     * Keeps the account's name in step with the category's.
     *
     * A renamed category whose account still reads the old name would make the
     * trial balance and the expense register disagree about what a line is.
     */
    public function renameAccountFor(ExpenseCategory $category, string $name): void
    {
        $category->chartAccount()->update(['name' => $this->accountName($name)]);
    }

    /**
     * The branch a headquarters expense is booked against.
     *
     * Head office is a branch like any other in this system — it holds a teller
     * cash account and appears in branch reporting — so an HQ cost is tagged to
     * it rather than left branch-less. Without this, HQ spending would fall
     * into the NULL-branch bucket of `account_balances` and no branch-scoped
     * report could see it at all.
     */
    public function headOffice(): Branch
    {
        /*
         * Read as an id rather than through the relation, for two reasons.
         *
         * The relation's real name is `headquarters` (CompanyProfile::66). Two
         * older callers spell it `headquartersBranch`, which is not a relation
         * at all — Eloquent finds no such method and hands back null, so both
         * silently always take the fallback below. That is harmless while the
         * profile's branch and the `is_head_office` flag agree, and wrong the
         * moment they do not. Naming a column cannot go wrong that way.
         *
         * And `headquarters_branch_id` is nullable while the relation accessor
         * is typed as though it were not, so a `?->headquarters ?? …` fallback
         * reads to static analysis as unreachable when it is the normal path.
         */
        $configuredId = CompanyProfile::query()->value('headquarters_branch_id');

        $branch = $configuredId === null
            ? null
            : Branch::query()->find($configuredId);

        $branch ??= Branch::query()->where('is_head_office', true)->first();

        if ($branch === null) {
            throw new RuntimeException(
                'No head-office branch is configured. Set one on the company profile before filing headquarters expenses.',
            );
        }

        return $branch;
    }

    /**
     * `6200-1`, `6200-2`, … — the next free dynamic expense code.
     *
     * Derived from the highest suffix in use rather than from a row count, for
     * the same reason `nextEntryNumber()` is: soft-deleted categories keep
     * their accounts, so counting would hand out a code that already exists.
     * `code` is uniquely indexed, so a concurrent second creator loses the
     * insert rather than silently sharing an account.
     */
    private function nextAccountCode(): string
    {
        $prefix = self::DYNAMIC_EXPENSE_PREFIX.'-';

        $highest = (int) ChartOfAccount::query()
            ->withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(code, ?) AS UNSIGNED)), 0) AS seq', [strlen($prefix) + 1])
            ->value('seq');

        return $prefix.($highest + 1);
    }

    /**
     * "Rent" → "Rent Expense", but "Bank Charges Expense" is left alone.
     *
     * The register holds names the company typed; the chart holds account
     * names. Appending unconditionally would produce "Rent Expense Expense" the
     * first time someone spells it out in full.
     */
    private function accountName(string $name): string
    {
        $trimmed = trim($name);

        return str_ends_with(mb_strtolower($trimmed), 'expense')
            ? $trimmed
            : $trimmed.' Expense';
    }
}
