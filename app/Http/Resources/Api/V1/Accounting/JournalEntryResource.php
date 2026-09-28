<?php

namespace App\Http\Resources\Api\V1\Accounting;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Accounting\JournalReversalGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin JournalEntry
 */
class JournalEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reverseBlockedReason = app(JournalReversalGuard::class)->blockedReason($this->resource);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'entry_date' => $this->entry_date?->toDateString(),
            'description' => $this->description,
            'transaction_type' => $this->transaction_type?->value,
            'transaction_type_label' => $this->transaction_type?->label(),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name) ?? 'HQ',
            'employee' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'source_type' => $this->source_type ? class_basename($this->source_type) : null,
            'source_label' => self::sourceLabel($this->source_type),
            'source_id' => $this->source_id,
            'total' => round((float) ($this->total ?? 0), 2),
            'reversal_of' => $this->whenLoaded('reversalOf', fn () => $this->reversalOf?->reference),
            'reversal_of_id' => $this->reversal_of_id,
            'reversal_reason' => $this->reversal_reason,
            'reversed_by' => $this->whenLoaded('reversal', fn () => $this->reversal?->reference),
            'is_reversed' => $this->whenLoaded('reversal', fn () => $this->reversal !== null),
            'can_reverse' => $reverseBlockedReason === null,
            'reverse_blocked_reason' => $reverseBlockedReason,
            'created_at' => $this->created_at?->toDateTimeString(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (JournalLine $line): array => [
                'id' => $line->id,
                'code' => $line->account?->code,
                'account' => $line->account?->name,
                'key' => $line->account?->key?->value,
                'scope' => $line->account ? implode(' / ', array_filter([
                    $line->account->branch?->name, $line->account->bankAccount?->name, $line->account->employee?->full_name, $line->account->expenseType?->name,
                ])) ?: 'HQ' : null,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
            ])),
        ];
    }

    /**
     * Human label for a morph class ("App\Models\LoanTransaction" → "Loan Transaction").
     */
    public static function sourceLabel(?string $type): string
    {
        return $type === null ? 'Manual' : Str::headline(class_basename($type));
    }
}
