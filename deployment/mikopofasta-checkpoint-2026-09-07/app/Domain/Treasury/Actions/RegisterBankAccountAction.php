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

            /* fill()+save() rather than create(): the attribute map is built
               by the DTO, and `fill` is the API that takes one. */
            $account = new BankAccount;
            $account->fill($data->toAttributes());
            $account->chart_account_id = (int) $chartAccount->getKey();
            $account->created_by = (int) $actor->getKey();
            $account->save();

            $opening = Money::of($data->openingBalance);

            if ($opening->isPositive()) {
                $entry = $this->ledger->post(
                    sprintf('Opening balance — %s %s', $data->bankName, $data->accountNumber),
                    JournalSourceType::CapitalInjection,
                    (int) $account->getKey(),
                    [
                        /* No branch on the line. The account is company-level,
                           so attributing its opening balance to one branch
                           would misstate that branch's position by the whole
                           amount. */
                        JournalLine::debit((int) $chartAccount->getKey(), $opening),
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
                    'account_type' => $account->account_type->value,
                    'usage' => $account->usage->value,
                    'bank_name' => $account->bank_name,
                    'account_number' => $account->account_number,
                    'chart_account_code' => $chartAccount->code,
                    'opening_balance' => $account->opening_balance,
                ],
                actor: $actor,
            );

            return $account->load(['chartAccount', 'bank', 'mobileMoneyProvider']);
        });
    }
}
