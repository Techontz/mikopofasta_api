<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serves two frontend shapes from one record, because the frontend has two
 * names for the same thing.
 *
 * `ExpenseNameSchema` (types/operations.ts) is what the two register screens
 * read: id, name, scope. `ExpenseCategorySchema` (types/expense.ts) is what the
 * Settings screen reads: the same, plus `chartAccountId`, `createdBy` and
 * `deletedAt`. Every field of both is emitted, so neither screen needs a
 * different endpoint and the two can never disagree about what exists.
 *
 * @mixin ExpenseCategory
 */
final class ExpenseCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'scope' => $this->scope->value,
            'scopeLabel' => $this->scope->label(),

            'chartAccountId' => (string) $this->chart_account_id,
            'createdBy' => $this->created_by === null ? null : (string) $this->created_by,
            'deletedAt' => $this->deleted_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),

            // The account code and the balance the category has accumulated —
            // what makes the register a budget view rather than a word list.
            // Only present when the caller asked for them, since computing a
            // balance per row is not free.
            'chartAccountCode' => $this->whenLoaded('chartAccount', fn (): string => $this->chartAccount->code),
            'spentToDate' => $this->when(
                $this->relationLoaded('chartAccount') && $this->chartAccount->relationLoaded('balances'),
                fn (): string => $this->chartAccount->cachedBalance()->toDecimalString(),
            ),
        ];
    }
}
