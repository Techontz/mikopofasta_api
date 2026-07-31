<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ExpenseRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `ExpenseClaimSchema` in the frontend's types/operations.ts.
 *
 * That schema declares `branch`, `staff` and `expense` as plain strings — the
 * table prints names, and one component serves both registers by choosing which
 * of them to show. So the names are emitted flat, exactly as declared, with the
 * ids alongside for callers that need to act on the record rather than draw it.
 *
 * `staff` is the requester's name. On the branch register the column is not
 * drawn at all; on the headquarters one it is the whole point, since a
 * headquarters cost is attributed to the person who incurred it rather than to
 * a branch.
 *
 * @mixin ExpenseRequest
 */
final class ExpenseRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'reference' => $this->reference,
            'scope' => $this->scope->value,

            // Flat names, as the frontend schema declares them. The default is
            // an empty string rather than null: the schema types all three as
            // `string`, and a table cell reading "null" is worse than one
            // reading nothing.
            'branch' => $this->whenLoaded('branch', fn (): string => $this->branch->name, ''),
            'staff' => $this->whenLoaded('requester', fn (): string => $this->requester->name, ''),
            'expense' => $this->whenLoaded('category', fn (): string => $this->category->name, ''),

            'amount' => $this->amount,
            'description' => $this->description,
            'comment' => $this->comment,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'date' => $this->requested_on->toDateString(),

            // Ids for the callers that act rather than draw.
            'branchId' => (string) $this->branch_id,

            // Set only for an expense paid from a bank account rather than out
            // of the branch till — Bank → Register Bank Expenses.
            'bankAccountId' => $this->bank_account_id === null ? null : (string) $this->bank_account_id,
            'bankName' => $this->whenLoaded(
                'bankAccount',
                fn (): ?string => $this->bank_account_id === null ? null : $this->bankAccount->bank_name,
            ),
            'bankAccountName' => $this->whenLoaded(
                'bankAccount',
                fn (): ?string => $this->bank_account_id === null ? null : $this->bankAccount->account_name,
            ),
            'expenseCategoryId' => (string) $this->expense_category_id,
            'requestedBy' => (string) $this->requested_by,
            'decidedBy' => $this->decided_by === null ? null : (string) $this->decided_by,
            'decidedByName' => $this->whenLoaded('decider', fn (): ?string => $this->decider?->name),
            'decidedAt' => $this->decided_at?->toIso8601String(),

            // Null until approved — the audit trail from the screen to the
            // trial balance, and the proof that a pending row posted nothing.
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,

            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
