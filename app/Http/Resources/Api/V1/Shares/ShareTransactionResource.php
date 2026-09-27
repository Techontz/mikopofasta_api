<?php

namespace App\Http\Resources\Api\V1\Shares;

use App\Enums\ShareTransactionType;
use App\Models\ShareTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShareTransaction
 */
class ShareTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $shareValue = (float) $this->share_value;
        $receipt = $this->payment_treatment === ShareTransaction::TREATMENT_PAID && $this->capital?->receipt_file
            ? "capital/capitals/{$this->capital_id}/receipt?v=".$this->capital->updated_at?->timestamp
            : null;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type->value,
            'type_label' => $this->type === ShareTransactionType::Reversal && $this->reversalOf !== null
                ? 'Reversal of '.$this->reversalOf->type->label()
                : $this->type->label(),
            'from_share_holder_id' => $this->from_share_holder_id,
            'from_share_holder' => $this->fromShareHolder?->full_name,
            'to_share_holder_id' => $this->to_share_holder_id,
            'to_share_holder' => $this->toShareHolder?->full_name,
            'shares' => $this->shares,
            'issued_change' => $this->issuedDelta(),
            'share_value' => $shareValue,
            'value_at_time' => round($this->shares * $shareValue, 2),
            'price_per_share' => $this->price_per_share === null ? null : (float) $this->price_per_share,
            'total_amount' => $this->total_amount === null ? null : (float) $this->total_amount,
            'payment_treatment' => $this->payment_treatment,
            'payment_treatment_label' => match ($this->payment_treatment) {
                ShareTransaction::TREATMENT_PAID => 'Paid — Dr Cash/Bank, Cr Share Capital',
                ShareTransaction::TREATMENT_LINKED => 'Linked to a recorded capital contribution',
                ShareTransaction::TREATMENT_NO_CASH => 'No cash — no journal entry',
                default => $this->type === ShareTransactionType::Transfer ? 'Between shareholders — no company ledger entry' : null,
            },
            'transacted_at' => $this->transacted_at?->format('Y-m-d H:i:s'),
            'date' => $this->transacted_at?->toDateString(),
            'status' => $this->status,
            'notes' => $this->notes,
            'capital_id' => $this->capital_id,
            'capital_amount' => $this->capital === null ? null : (float) $this->capital->amount,
            'receiving_account' => $this->capital?->receivingAccountLabel(),
            'receipt_number' => $this->capital?->receipt_number,
            'journal_entry_id' => $this->journal_entry_id,
            'journal_reference' => $this->journalEntry?->reference,
            'reversal_of_id' => $this->reversal_of_id,
            'reversal_of_reference' => $this->reversalOf?->reference,
            'reversed_by_reference' => $this->reversal?->reference,
            'document_name' => $this->document_name ?? ($receipt ? $this->capital?->receipt_file_name : null),
            'document_endpoint' => $this->document_path ? "shares/transactions/{$this->id}/document?v=".$this->updated_at?->timestamp : $receipt,
            'performed_by' => $this->performer?->full_name,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
