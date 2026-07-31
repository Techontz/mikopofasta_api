<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Treasury\Exceptions\BankAccountInUseException;
use App\Domain\Treasury\Services\BankAccountResolver;
use App\Enums\AuditAction;
use App\Models\BankAccount;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Closes an account.
 *
 * Soft-deletes the record and deactivates its chart account. Never removes the
 * chart account: it holds every shilling that passed through, and last year's
 * balance sheet still has to be able to read it.
 *
 * Refused while the account still holds money, or while a movement against it
 * is waiting on a decision. Both are the same objection — closing an account
 * out from under a live obligation loses track of real money — and neither is
 * something a later reconciliation could recover from.
 */
final class DeleteBankAccountAction
{
    public function __construct(
        private readonly BankAccountResolver $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(BankAccount $account, User $actor): void
    {
        $account->loadMissing('chartAccount.balances');

        $balance = $account->currentBalance();

        if (! $balance->isZero()) {
            throw BankAccountInUseException::hasBalance($account->account_name, $balance->toDecimalString());
        }

        if ($this->hasPendingMovements($account)) {
            throw BankAccountInUseException::hasPendingMovements($account->account_name);
        }

        DB::transaction(function () use ($account, $actor): void {
            $this->accounts->deactivateAccountFor($account);

            $this->audit->log(
                AuditAction::BankAccountClosed,
                $account,
                before: [
                    'bank_name' => $account->bank_name,
                    'account_number' => $account->account_number,
                ],
                actor: $actor,
            );

            $account->delete();
        });
    }

    private function hasPendingMovements(BankAccount $account): bool
    {
        return DB::table('bank_transactions')
            ->whereNull('deleted_at')
            ->where('bank_account_id', $account->getKey())
            ->where('status', 'pending')
            ->exists()
            || DB::table('bank_transfers')
                ->whereNull('deleted_at')
                ->where('status', 'pending')
                ->where(fn ($q) => $q
                    ->where('from_account_id', $account->getKey())
                    ->orWhere('to_account_id', $account->getKey()))
                ->exists();
    }
}
