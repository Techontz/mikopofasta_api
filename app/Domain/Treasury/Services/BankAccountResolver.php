<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Services;

use App\Domain\Ledger\Enums\AccountType;
use App\Enums\ActiveStatus;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;

/**
 * Mints and maintains the 8xxx chart account a bank account owns.
 *
 * §2.2: "every bank account owns an 8xxx chart account". ChartOfAccountSeeder
 * allocates them at 8000 and 8010 — sequential in tens — and this continues
 * that rather than inventing a second scheme for accounts created through the
 * UI, so the chart reads the same however a row got there.
 */
final class BankAccountResolver
{
    private const FIRST_CODE = 8000;

    private const STEP = 10;

    /**
     * Creates the ledger account a bank account will own.
     *
     * Called immediately before the bank account itself, inside the same
     * transaction — `bank_accounts.chart_account_id` is written from the
     * result, and an account without a ledger could not be posted to.
     */
    public function createAccountFor(BankAccount|string $bankName, ?string $accountName = null): ChartOfAccount
    {
        $name = $bankName instanceof BankAccount
            ? $this->accountName($bankName->bank_name, $bankName->account_name)
            : $this->accountName($bankName, $accountName ?? '');

        return ChartOfAccount::query()->create([
            'code' => $this->nextCode(),
            'name' => $name,
            'type' => AccountType::Asset,
            'is_system' => false,
            // Not branch-scoped: one account can serve several branches, and
            // the branch dimension on each journal line is what separates their
            // activity on it.
            'branch_id' => null,
            'status' => ActiveStatus::Active,
        ]);
    }

    /** Keeps the chart account's name in step with the bank account's. */
    public function renameAccountFor(BankAccount $account): void
    {
        $account->chartAccount()->update([
            'name' => $this->accountName($account->bank_name, $account->account_name),
        ]);
    }

    /**
     * Takes the chart account out of service when its bank account is retired.
     *
     * Deactivated rather than deleted: it holds every shilling that ever passed
     * through the account. LedgerService refuses to post to an inactive
     * account, which is what makes a closed account actually closed rather than
     * merely hidden from the picker.
     */
    public function deactivateAccountFor(BankAccount $account): void
    {
        $account->chartAccount()->update(['status' => ActiveStatus::Inactive]);
    }

    public function reactivateAccountFor(BankAccount $account): void
    {
        $account->chartAccount()->update(['status' => ActiveStatus::Active]);
    }

    /**
     * The next free 8xxx code.
     *
     * Derived from the highest in use rather than a row count, so a
     * soft-deleted account never lends its code to a new one — codes are
     * unique, and reusing one would attach new activity to an old account's
     * history.
     */
    private function nextCode(): string
    {
        $highest = (int) ChartOfAccount::query()
            ->withTrashed()
            ->whereRaw("code REGEXP '^8[0-9]{3}$'")
            ->selectRaw('COALESCE(MAX(CAST(code AS UNSIGNED)), 0) AS seq')
            ->value('seq');

        return (string) ($highest === 0 ? self::FIRST_CODE : $highest + self::STEP);
    }

    /** "CRDB Bank — Mikopofasta Microfinance Limited". */
    private function accountName(string $bankName, string $accountName): string
    {
        $bank = trim($bankName);
        $holder = trim($accountName);

        return $holder === '' ? $bank : "{$bank} — {$holder}";
    }
}
