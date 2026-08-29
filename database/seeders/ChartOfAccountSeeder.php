<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Enums\ActiveStatus;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

/**
 * The chart of accounts — spec §5.
 *
 * The 18 fixed system accounts, plus the dynamic ones §5 describes: one 8xxx
 * account per bank account, and one Teller Cash account per branch (§12, which
 * is what lets Branch P&L and Branch Ledger work as simple filtered queries).
 *
 * Account types come from SystemAccountCode::type(), so the definition lives in
 * one place. Note Principal is EQUITY rather than an asset — see the note on
 * that enum for why.
 */
class ChartOfAccountSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SystemAccountCode::cases() as $code) {
            ChartOfAccount::query()->updateOrCreate(
                ['code' => $code->value],
                [
                    'name' => $code->accountName(),
                    'type' => $code->type(),
                    'is_system' => true,
                    'branch_id' => null,
                    'status' => ActiveStatus::Active,
                ],
            );
        }

        $this->seedBankAccounts();
        $this->seedTellerCashAccounts();
    }

    /**
     * The bank accounts to create — none, unless a subclass supplies them.
     *
     * A seam rather than a flag: DemoBankAccountSeeder overrides it with the
     * demonstration rows, and production gets an empty list without this class
     * needing to know which environment it is in.
     *
     * @return list<array{bank_name: string, account_number: string, account_name: string, code: string}>
     */
    protected function bankAccounts(): array
    {
        return [];
    }

    /**
     * Bank accounts and their 8xxx chart accounts (§2.2).
     *
     * Backend support only — the frontend has no bank-account CRUD screen
     * (readiness report gap 3), so these are seeded and read, never managed
     * through an endpoint.
     */
    /**
     * The institution's own bank accounts.
     *
     * DELIBERATELY EMPTY IN PRODUCTION. Which banks a microfinance holds its
     * float with, and under what account numbers, is the institution's to
     * state — not this seeder's to guess. It used to create two named accounts,
     * which meant a fresh installation arrived believing it banked with
     * particular institutions at particular numbers.
     *
     * The rows moved to DemoBankAccountSeeder, which only the development and
     * test seeder runs. A production install creates the chart of accounts —
     * which IS structure, since the ledger posts to accounts by code — and no
     * bank account at all; they are added at Treasury → Bank Accounts.
     *
     * `seedTellerCashAccounts()` below needs no such treatment: it creates one
     * account per EXISTING branch, so on a fresh install with no branches it
     * creates nothing and adjusts itself as branches are added.
     */
    private function seedBankAccounts(): void
    {
        /** @var list<array{bank_name: string, account_number: string, account_name: string, code: string}> $banks */
        $banks = $this->bankAccounts();

        foreach ($banks as $bank) {
            $chartAccount = ChartOfAccount::query()->updateOrCreate(
                ['code' => $bank['code']],
                [
                    'name' => $bank['bank_name'].' — '.$bank['account_number'],
                    'type' => AccountType::Asset,
                    'is_system' => false,
                    'branch_id' => null,
                    'status' => ActiveStatus::Active,
                ],
            );

            BankAccount::query()->updateOrCreate(
                ['account_number' => $bank['account_number']],
                [
                    'bank_name' => $bank['bank_name'],
                    'account_name' => $bank['account_name'],
                    'chart_account_id' => $chartAccount->getKey(),
                    'status' => ActiveStatus::Active,
                ],
            );
        }
    }

    /**
     * One Teller Cash account per branch (§12).
     *
     * Created through AccountResolver rather than inline, so the seed and the
     * runtime path produce identical rows — the same principle as the loan
     * schedule generator.
     */
    private function seedTellerCashAccounts(): void
    {
        $resolver = app(AccountResolver::class);

        foreach (Branch::query()->get() as $branch) {
            $resolver->tellerCash($branch);
        }
    }
}
