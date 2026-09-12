<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Treasury\DTOs\BankAccountData;
use App\Domain\Treasury\Services\BankAccountResolver;
use App\Enums\ActiveStatus;
use App\Enums\AuditAction;
use App\Models\BankAccount;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Edits a registered account.
 *
 * `opening_balance` is deliberately not editable. It is the figure an entry
 * already posted, and changing the number without reversing the entry would
 * make the account's own screen disagree with the ledger. Correcting one means
 * reversing that entry, which is the Ledger module's job.
 */
final class UpdateBankAccountAction
{
    public function __construct(
        private readonly BankAccountResolver $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(BankAccount $account, BankAccountData $data, User $actor): BankAccount
    {
        return DB::transaction(function () use ($account, $data, $actor): BankAccount {
            $before = $account->only([
                'account_type', 'usage', 'bank_name', 'bank_id', 'mobile_money_provider_id',
                'account_number', 'account_name', 'currency', 'description', 'status',
            ]);

            $wasActive = $account->status === ActiveStatus::Active;

            /*
             * `opening_balance` is deliberately not among these. It was posted
             * to the ledger when the account was registered; changing the
             * column now would leave the stored figure and the journal entry
             * disagreeing, and the ledger is the one that is right.
             *
             * The uniqueness keys are not here either — the database derives
             * them, so editing the account number re-derives them by itself.
             */
            $account->update(collect($data->toAttributes())->except('opening_balance')->all());

            $this->accounts->renameAccountFor($account);

            /*
             * The chart account follows the bank account's status, so a
             * deactivated account cannot be posted to — LedgerService refuses
             * an inactive account, which is what makes "inactive" mean
             * something beyond a badge on a table row.
             */
            $isActive = $account->status === ActiveStatus::Active;

            if ($isActive !== $wasActive) {
                $isActive
                    ? $this->accounts->reactivateAccountFor($account)
                    : $this->accounts->deactivateAccountFor($account);
            }

            $this->audit->log(
                AuditAction::BankAccountUpdated,
                $account,
                before: $before,
                after: $account->only(array_keys($before)),
                actor: $actor,
            );

            return $account->load(['chartAccount', 'bank', 'mobileMoneyProvider']);
        });
    }
}
