<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Treasury\DTOs\BankAccountData;
use App\Domain\Treasury\Services\BankAccountResolver;
use App\Enums\AuditAction;
use App\Models\BankAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Registers a bank account — Bank → Register Account.
 *
 * Three things in one transaction: the 8xxx chart account, the bank account
 * that owns it, and — if an opening balance was given — the entry that puts the
 * money there.
 *
 * That last part is the one worth explaining. An opening balance is not a
 * number typed into a column; it is money the company already has, and §5's
 * rule is that every shilling passes through the ledger. So it posts:
 *
 *     Dr  the new 8xxx bank account
 *     Cr  1000 Capital
 *
 * Capital, because money that exists at the moment an account is opened came
 * from the owners rather than from operations. Booking it anywhere else would
 * either invent income the company never earned or leave the trial balance
 * one-sided.
 */
final class RegisterBankAccountAction
{
    public function __construct(
        private readonly BankAccountResolver $accounts,
        private readonly AccountResolver $chart,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(BankAccountData $data, User $actor): BankAccount
    {
        return DB::transaction(function () use ($data, $actor): BankAccount {
            $chartAccount = $this->accounts->createAccountFor($data->bankName, $data->accountName);

            $account = BankAccount::query()->create([
                'bank_name' => $data->bankName,
                'account_number' => $data->accountNumber,
                'account_name' => $data->accountName,
                'branch_id' => $data->branchId,
                'currency' => $data->currency,
                'opening_balance' => $data->openingBalance,
                'description' => $data->description,
                'chart_account_id' => $chartAccount->getKey(),
                'status' => $data->status,
                'created_by' => $actor->getKey(),
            ]);

            $opening = Money::of($data->openingBalance);

            if ($opening->isPositive()) {
                $entry = $this->ledger->post(
                    sprintf('Opening balance — %s %s', $data->bankName, $data->accountNumber),
                    JournalSourceType::CapitalInjection,
                    (int) $account->getKey(),
                    [
                        JournalLine::debit((int) $chartAccount->getKey(), $opening, branchId: $data->branchId),
                        JournalLine::credit($this->chart->systemId(SystemAccountCode::Capital), $opening),
                    ],
                    $actor,
                );

                $account->setRelation('openingEntry', $entry);
            }

            $this->audit->log(
                AuditAction::BankAccountRegistered,
                $account,
                after: [
                    'bank_name' => $account->bank_name,
                    'account_number' => $account->account_number,
                    'chart_account_code' => $chartAccount->code,
                    'opening_balance' => $account->opening_balance,
                ],
                actor: $actor,
            );

            return $account->load(['chartAccount', 'branch']);
        });
    }
}
