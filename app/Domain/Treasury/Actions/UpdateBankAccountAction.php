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
                'bank_name', 'account_number', 'account_name', 'branch_id',
                'currency', 'description', 'status',
            ]);

            $wasActive = $account->status === ActiveStatus::Active;

            $account->update([
                'bank_name' => $data->bankName,
                'account_number' => $data->accountNumber,
                'account_name' => $data->accountName,
                'branch_id' => $data->branchId,
                'currency' => $data->currency,
                'description' => $data->description,
                'status' => $data->status,
            ]);

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

            return $account->load(['chartAccount', 'branch']);
        });
    }
}
