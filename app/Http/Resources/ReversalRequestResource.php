<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReversalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `ReversalRequestSchema` in the frontend's types/ledger.ts.
 *
 * `subjectLabel` is computed here rather than in the browser because the
 * queue's whole job is telling an approver what they are about to undo, and
 * each type names its subject in a different place. A frontend
 * assembling that string would need all four relations loaded and would get it
 * subtly wrong the first time a type was added.
 *
 * @mixin ReversalRequest
 */
final class ReversalRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'reversalType' => $this->reversal_type->value,
            'reversalTypeLabel' => $this->reversal_type->label(),

            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,
            'paymentId' => $this->payment_id === null ? null : (string) $this->payment_id,
            'disbursementBatchId' => $this->disbursement_batch_id === null ? null : (string) $this->disbursement_batch_id,
            'loanScheduleId' => $this->loan_schedule_id === null ? null : (string) $this->loan_schedule_id,
            'customerAdvancePaymentId' => $this->customer_advance_payment_id === null
                ? null
                : (string) $this->customer_advance_payment_id,
            'loanId' => $this->loan_id === null ? null : (string) $this->loan_id,

            'requestedBy' => (string) $this->requested_by,
            'requestedByName' => $this->whenLoaded('requester', fn (): ?string => $this->requester?->name),
            'reason' => $this->reason,
            'amount' => $this->amount,

            'approvedBy' => $this->approved_by === null ? null : (string) $this->approved_by,
            'approvedByName' => $this->whenLoaded('approver', fn (): ?string => $this->approver?->name),
            'status' => $this->status->value,
            'decidedAt' => $this->decided_at?->toIso8601String(),
            'decisionNote' => $this->decision_note,
            'reversalEntryId' => $this->reversal_entry_id === null ? null : (string) $this->reversal_entry_id,
            'createdAt' => $this->created_at?->toIso8601String(),

            'entryNumber' => $this->whenLoaded('journalEntry', fn (): ?string => $this->journalEntry?->entry_number),
            'reversalEntryNumber' => $this->whenLoaded('reversalEntry', fn (): ?string => $this->reversalEntry?->entry_number),
            'loanNumber' => $this->whenLoaded('loan', fn (): ?string => $this->loan?->loan_number),
            'paymentReference' => $this->whenLoaded('payment', fn (): ?string => $this->payment?->payment_reference),
            'batchReference' => $this->whenLoaded(
                'disbursementBatch',
                fn (): ?string => $this->disbursementBatch?->batch_reference,
            ),
            'installmentNumber' => $this->whenLoaded(
                'loanSchedule',
                fn (): ?int => $this->loanSchedule?->installment_number,
            ),

            'advancePaymentReference' => $this->whenLoaded(
                'advancePayment',
                fn (): ?string => $this->advancePayment?->reference,
            ),

            'subjectLabel' => $this->subjectLabel(),
        ];
    }

    private function subjectLabel(): string
    {
        return match (true) {
            $this->payment_id !== null => sprintf(
                'Payment %s',
                $this->payment?->payment_reference ?? '#'.$this->payment_id,
            ),
            $this->disbursement_batch_id !== null => sprintf(
                'Disbursement %s',
                $this->disbursementBatch?->batch_reference ?? '#'.$this->disbursement_batch_id,
            ),
            $this->loan_schedule_id !== null => sprintf(
                'Penalty on installment %s',
                $this->loanSchedule?->installment_number ?? '#'.$this->loan_schedule_id,
            ),
            $this->customer_advance_payment_id !== null => sprintf(
                'Salary advance payment %s',
                $this->advancePayment?->reference ?? '#'.$this->customer_advance_payment_id,
            ),
            $this->journal_entry_id !== null => sprintf(
                'Entry %s',
                $this->journalEntry?->entry_number ?? '#'.$this->journal_entry_id,
            ),
            default => 'Unknown subject',
        };
    }
}
