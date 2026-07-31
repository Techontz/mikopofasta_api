<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Treasury\Enums\HqTransactionStatus;
use App\Domain\Treasury\Exceptions\HqTransactionInvalidException;
use App\Enums\AuditAction;
use App\Models\HqAccount;
use App\Models\HqAccountTransfer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Approves or rejects a headquarters movement, and moves the money if approved.
 *
 * Why this touches `hq_accounts.balance` directly rather than posting to the
 * §5 ledger: the seven headquarters pots are **not** the chart of accounts. The
 * original migration is explicit about it — DISBURSEMENT ACCOUNT and SAVING
 * ACCOUNT have no §5 counterpart at all, and folding them in would have meant
 * inventing two system codes or dropping two accounts. This is a small internal
 * ledger of its own, and `balance` is stored because the seven legacy balances
 * are known while the transfers that produced them are not.
 *
 * That stored balance is the reason for the overdraw check below. A derived
 * balance cannot go wrong without an entry to explain it; a stored one can, so
 * the only protection is refusing the movement that would do it.
 */
final class DecideHqTransactionAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(
        HqAccountTransfer $transfer,
        HqTransactionStatus $decision,
        User $actor,
    ): HqAccountTransfer {
        if (! $transfer->status->isDecidable()) {
            throw HqTransactionInvalidException::notPending();
        }

        return DB::transaction(function () use ($transfer, $decision, $actor): HqAccountTransfer {
            if ($decision === HqTransactionStatus::Approved) {
                $this->applyToBalances($transfer);
            }

            $transfer->update([
                'status' => $decision,
                'approved_by' => $actor->getKey(),
                // Named `approved_on` by the legacy screen; it records when the
                // decision was made, whichever way it went.
                'approved_on' => Date::now()->toDateString(),
            ]);

            $this->audit->log(
                $decision === HqTransactionStatus::Approved
                    ? AuditAction::HqTransactionApproved
                    : AuditAction::HqTransactionRejected,
                $transfer,
                before: ['status' => HqTransactionStatus::Pending->value],
                after: ['status' => $decision->value, 'amount' => $transfer->amount],
                actor: $actor,
            );

            return $transfer->load(HqAccountTransfer::LIST_RELATIONS);
        });
    }

    /**
     * Debits the source pot and credits the destination, whichever exist.
     *
     * Rows are locked for update so two approvals landing together cannot both
     * read the same starting balance and each write it back — the classic
     * lost update, and with a stored balance there is no journal to reconcile
     * it against afterwards.
     */
    private function applyToBalances(HqAccountTransfer $transfer): void
    {
        $amount = $transfer->amount();

        if ($transfer->from_account_id !== null) {
            $from = HqAccount::query()->lockForUpdate()->findOrFail($transfer->from_account_id);
            $available = $from->balance();

            if ($available->lessThan($amount)) {
                throw HqTransactionInvalidException::insufficientBalance(
                    $from->name,
                    $available->toDecimalString(),
                );
            }

            $from->update(['balance' => $available->subtract($amount)->toDecimalString()]);
        }

        if ($transfer->to_account_id !== null) {
            $to = HqAccount::query()->lockForUpdate()->findOrFail($transfer->to_account_id);
            $to->update(['balance' => $to->balance()->add($amount)->toDecimalString()]);
        }
    }
}
