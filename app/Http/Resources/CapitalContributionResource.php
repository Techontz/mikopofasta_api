<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CapitalContribution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `CapitalContributionSchema` in the frontend's types/capital.ts.
 *
 * @mixin CapitalContribution
 */
final class CapitalContributionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'shareholderId' => (string) $this->shareholder_id,
            'amount' => $this->amount,
            'payMethod' => $this->pay_method->value,
            'payMethodLabel' => $this->pay_method->label(),
            'receiptNo' => $this->receipt_no,
            'chequeNo' => $this->cheque_no,
            'createdAt' => $this->created_at?->toIso8601String(),
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,

            'shareholderName' => $this->whenLoaded('shareholder', fn (): string => $this->shareholder->full_name),
        ];
    }
}
