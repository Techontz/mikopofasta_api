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
final class ChartOfAccountSeeder extends Seeder
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
     * Bank accounts and their 8xxx chart accounts (§2.2).
     *
     * Backend support only — the frontend has no bank-account CRUD screen
     * (readiness report gap 3), so these are seeded and read, never managed
     * through an endpoint.
     */
    private function seedBankAccounts(): void
    {
        $banks = [
            ['bank_name' => 'CRDB Bank', 'account_number' => '0150312345600', 'account_name' => 'Mikopofasta Microfinance Limited', 'code' => '8000'],
            ['bank_name' => 'NMB Bank', 'account_number' => '2011098765400', 'account_name' => 'Mikopofasta Microfinance Limited', 'code' => '8010'],
        ];

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
