<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\HqAccountTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `HqTransactionSchema` in the frontend's types/operations.ts.
 *
 * That schema declares `branch`, `requestedBy` and `approvedBy` as printed
 * names, so they are emitted flat, with ids alongside for callers that act on
 * the record rather than draw it.
 *
 * `requestedBy` falls back to the legacy `staff_name` column. New records name
 * a real user; imported legacy rows will only ever have the text, and a screen
 * showing an empty Requested By for every historical row would be worse than
 * showing the name the old system recorded.
 *
 * @mixin HqAccountTransfer
 */
final class HqTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'reference' => $this->reference,

            /*
             * Keyed off the foreign key rather than the relation. Both columns
             * are nullable — a movement need not concern a branch, and an
             * imported legacy row has no user — but the relation accessors are
             * typed as though they were not, so testing the id is both honest
             * about what can be missing and legible to static analysis.
             */
            'branch' => $this->whenLoaded(
                'branch',
                fn (): string => $this->branch_id === null ? '' : $this->branch->name,
                '',
            ),
            'requestedBy' => $this->whenLoaded(
                'requester',
                fn (): string => $this->requested_by === null
                    ? ($this->staff_name ?? '')
                    : $this->requester->name,
                $this->staff_name ?? '',
            ),
            'approvedBy' => $this->whenLoaded(
                'approver',
                fn (): ?string => $this->approved_by === null ? null : $this->approver->name,
            ),

            'amount' => $this->amount,
            'reason' => $this->reason ?? '',
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'date' => $this->requested_on->toDateString(),
            'direction' => $this->direction->value,
            'directionLabel' => $this->direction->label(),

            // The two pots. Null on a one-sided movement — money arriving names
            // only where it landed, money leaving only where it came from.
            'fromAccountId' => $this->from_account_id === null ? null : (string) $this->from_account_id,
            'toAccountId' => $this->to_account_id === null ? null : (string) $this->to_account_id,
            'fromAccount' => $this->whenLoaded(
                'fromAccount',
                fn (): ?string => $this->from_account_id === null ? null : $this->fromAccount->name,
            ),
            'toAccount' => $this->whenLoaded(
                'toAccount',
                fn (): ?string => $this->to_account_id === null ? null : $this->toAccount->name,
            ),

            'branchId' => $this->branch_id === null ? null : (string) $this->branch_id,
            'approvedOn' => $this->approved_on?->toDateString(),

            /*
             * The legacy Charger column. Its values were never captured, so its
             * meaning is inferred from position alone — passed through
             * untouched rather than interpreted.
             */
            'charger' => $this->charger,
        ];
    }
}
