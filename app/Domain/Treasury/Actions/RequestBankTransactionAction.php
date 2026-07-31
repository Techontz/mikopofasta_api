<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Treasury\Enums\BankTransactionStatus;
use App\Domain\Treasury\Enums\BankTransactionType;
use App\Enums\AuditAction;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Raises a bank movement — Bank → Bank Transaction.
 *
 * Posts nothing. The balance changes when someone approves, which keeps a queue
 * of proposals out of the trial balance — the same rule expenses, float and
 * headquarters movements all follow.
 */
final class RequestBankTransactionAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(
        BankAccount $account,
        BankTransactionType $type,
        string $amount,
        ?int $branchId,
        ?string $note,
        ?string $transactedOn,
        User $actor,
    ): BankTransaction {
        return DB::transaction(function () use ($account, $type, $amount, $branchId, $note, $transactedOn, $actor): BankTransaction {
            $transaction = BankTransaction::query()->create([
                'reference' => $this->nextReference(),
                'bank_account_id' => $account->getKey(),
                'type' => $type,
                'amount' => $amount,
                // Defaults to the requester's branch, so a branch officer
                // recording a deposit does not have to name their own branch.
                'branch_id' => $branchId ?? $actor->branch_id,
                'requested_by' => $actor->getKey(),
                'status' => BankTransactionStatus::Pending,
                'note' => $note,
                'transacted_on' => $transactedOn ?? Date::now()->toDateString(),
            ]);

            $this->audit->log(
                AuditAction::BankTransactionRequested,
                $transaction,
                after: [
                    'reference' => $transaction->reference,
                    'bank_account_id' => $account->getKey(),
                    'type' => $type->value,
                    'amount' => $transaction->amount,
                ],
                actor: $actor,
            );

            return $transaction->load(BankTransaction::LIST_RELATIONS);
        });
    }

    /** BNK-0000001, from the highest in use. */
    private function nextReference(): string
    {
        $highest = (int) DB::table('bank_transactions')
            ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)), 0) AS seq')
            ->value('seq');

        return 'BNK-'.str_pad((string) ($highest + 1), 7, '0', STR_PAD_LEFT);
    }
}
