<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Treasury\DTOs\HqTransactionData;
use App\Domain\Treasury\Enums\HqTransactionDirection;
use App\Domain\Treasury\Enums\HqTransactionStatus;
use App\Domain\Treasury\Exceptions\HqTransactionInvalidException;
use App\Enums\AuditAction;
use App\Models\HqAccountTransfer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Raises a headquarters movement — Headquarters Transaction → Requested
 * Transactions.
 *
 * Moves nothing. `hq_accounts.balance` changes on approval, not on request,
 * which is the same rule branch-to-branch float and expense approval follow —
 * a queue of requests must never be visible in the balance screen.
 */
final class RequestHqTransactionAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(HqTransactionData $data, User $actor): HqAccountTransfer
    {
        $this->guardSides($data);

        return DB::transaction(function () use ($data, $actor): HqAccountTransfer {
            $transfer = HqAccountTransfer::query()->create([
                'reference' => $this->nextReference(),
                'from_account_id' => $data->fromAccountId,
                'to_account_id' => $data->toAccountId,
                'branch_id' => $data->branchId,
                'amount' => $data->amount,
                'direction' => $data->direction,
                'reason' => $data->reason,
                'requested_by' => $actor->getKey(),
                'status' => HqTransactionStatus::Pending,
                'requested_on' => $data->requestedOn ?? Date::now()->toDateString(),
            ]);

            $this->audit->log(
                AuditAction::HqTransactionRequested,
                $transfer,
                after: [
                    'reference' => $transfer->reference,
                    'direction' => $data->direction->value,
                    'amount' => $transfer->amount,
                    'from_account_id' => $data->fromAccountId,
                    'to_account_id' => $data->toAccountId,
                ],
                actor: $actor,
            );

            return $transfer->load(HqAccountTransfer::LIST_RELATIONS);
        });
    }

    /**
     * Each direction names the sides it has.
     *
     * Checked here rather than only in the form request because the rule is
     * about the shape of the record, not the shape of one HTTP payload — the
     * seeder builds these too, and it must obey the same rule.
     */
    private function guardSides(HqTransactionData $data): void
    {
        match ($data->direction) {
            HqTransactionDirection::In => $data->toAccountId !== null && $data->fromAccountId === null
                ? null
                : throw HqTransactionInvalidException::wrongSides(
                    'Money arriving names the account it landed in, and nothing else.',
                ),

            HqTransactionDirection::Out => $data->fromAccountId !== null && $data->toAccountId === null
                ? null
                : throw HqTransactionInvalidException::wrongSides(
                    'Money leaving names the account it came from, and nothing else.',
                ),

            HqTransactionDirection::Internal => $data->fromAccountId !== null && $data->toAccountId !== null
                ? null
                : throw HqTransactionInvalidException::wrongSides(
                    'A transfer between accounts names both sides.',
                ),
        };

        if ($data->direction === HqTransactionDirection::Internal
            && $data->fromAccountId === $data->toAccountId) {
            throw HqTransactionInvalidException::sameAccount();
        }
    }

    /**
     * HQT-0000001. From the highest in use rather than a row count, so a
     * soft-deleted request never lends its reference to a new one.
     */
    private function nextReference(): string
    {
        $highest = (int) DB::table('hq_account_transfers')
            ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)), 0) AS seq')
            ->value('seq');

        return 'HQT-'.str_pad((string) ($highest + 1), 7, '0', STR_PAD_LEFT);
    }
}
