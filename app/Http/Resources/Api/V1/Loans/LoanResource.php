<?php

namespace App\Http\Resources\Api\V1\Loans;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Services\CustomerEligibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Loan row used by every loan list (pending, credit review, disbursement, disbursed, withdrawal, rejected).
 *
 * @mixin Loan
 */
class LoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'loan_number' => $this->loan_number,
            'reference_number' => $this->reference_number,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->full_name),
            'customer_phone' => $this->whenLoaded('customer', fn () => $this->customer?->phone),
            'customer_status' => $this->whenLoaded('customer', fn () => $this->customer?->status),
            'customer_status_label' => $this->whenLoaded('customer', fn () => $this->customer?->status_label),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'loan_category_id' => $this->loan_category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
            // Customer type of the loan category (only when category.customerType was eager loaded).
            'category_customer_type' => $this->when($this->relationLoaded('category') && $this->category?->relationLoaded('customerType'), fn () => $this->category?->customerType?->name),
            'requires_mandate' => $this->whenLoaded('category', fn () => (bool) $this->category?->requires_mandate),
            'group_id' => $this->group_id,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'amount_applied' => (float) $this->amount_applied,
            'amount_approved' => (float) $this->amount_approved,
            'interest_rate' => (float) $this->interest_rate,
            'interest_amount' => (float) $this->interest_amount,
            'total_payable' => (float) $this->total_payable,
            'restoration' => (float) $this->restoration,
            'instalment' => (float) $this->instalment,
            'loan_fee' => (float) $this->loan_fee,
            'insurance' => (float) $this->insurance,
            'fee_deduct' => $this->fee_deduct,
            'formula' => $this->formula,
            'duration' => $this->duration?->value,
            'duration_label' => $this->duration?->label(),
            'sessions' => $this->sessions,
            'reason' => $this->reason,
            'is_special' => $this->is_special,
            // Carried over from the old system (legacy import): the figures its Loan File printed and the January–September
            // history, which is shown for reference and never reduced the balance.
            'is_legacy_opening' => (bool) $this->is_legacy_opening,
            'opening_paid' => (float) $this->opening_paid_principal,
            'legacy' => $this->is_legacy_opening && $this->relationLoaded('legacyImportRow') ? $this->legacyDetails() : null,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_badge' => $this->status->badge(),
            'decision_reason' => $this->decision_reason,
            'telco_name' => $this->telco_name,
            'telco_matched' => $this->telco_matched,
            'telco_verified_at' => $this->telco_verified_at?->toDateTimeString(),
            'disbursement_channel' => $this->disbursement_channel,
            'disbursement_attempts' => (int) $this->disbursement_attempts,
            'latest_disbursement' => $this->whenLoaded('latestDisbursement', fn () => $this->latestDisbursement ? [
                'batch_id' => $this->latestDisbursement->batch_id,
                'attempt' => $this->latestDisbursement->attempt,
                'channel' => $this->latestDisbursement->channel,
                'amount' => (float) $this->latestDisbursement->amount,
                'status' => $this->latestDisbursement->status,
                'failure_reason' => $this->latestDisbursement->failure_reason,
                'source_account' => $this->latestDisbursement->source_account ?? LoanDisbursement::SOURCE_CASH,
                'source_bank_account_id' => $this->latestDisbursement->source_bank_account_id,
                'source_label' => $this->latestDisbursement->sourceLabel(),
                'provider_reference' => $this->latestDisbursement->provider_reference,
                'journal_reference' => $this->latestDisbursement->journalEntry?->reference,
                'completed_at' => $this->latestDisbursement->completed_at?->toDateTimeString(),
            ] : null),
            'days_past_due' => (int) $this->days_past_due,
            'topup_of_loan_id' => $this->topup_of_loan_id,
            'agreement_file' => $this->agreement_file ? asset('storage/'.$this->agreement_file) : null,
            'agreement_uploaded_at' => $this->agreement_uploaded_at?->toDateTimeString(),
            // Generated after branch manager approval; the signed copy must be uploaded before credit approval.
            'agreement_available' => in_array($this->status, LoanStatus::agreementAvailable(), true),
            'created_at' => $this->created_at?->toDateString(),
            'approved_at' => $this->approved_at?->toDateString(),
            'disbursed_at' => $this->disbursed_at?->toDateTimeString(),
            'withdrawn_at' => $this->withdrawn_at?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'closed_at' => $this->closed_at?->toDateString(),
            // Early full settlement freeze (LoanService::recordSettlement()): disbursement → maturity → settlement.
            'disbursed_at_iso' => $this->disbursed_at?->toIso8601String(),
            'expected_completion_date' => ($this->expected_completion_date ?? $this->end_date)?->toDateString(),
            'settled_at' => $this->closed_at?->toIso8601String(),
            'early_settlement' => $this->early_settlement,
            'freeze_started_at' => $this->freeze_started_at?->toIso8601String(),
            'freeze_days' => $this->freeze_days,
            'frozen_until' => $this->frozen_until?->toIso8601String(),
            'frozen_until_label' => $this->frozen_until ? CustomerEligibility::freezeUntilLabel($this->frozen_until) : null,
            'freeze_status' => $this->resource->freezeStatus(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function legacyDetails(): ?array
    {
        $row = $this->resource->legacyImportRow;

        return $row === null ? null : [
            'import_id' => $row->legacy_import_id,
            'file_name' => $row->import?->file_name,
            'row_number' => $row->row_number,
            'year' => $row->import?->year,
            'approved_at' => $row->import?->approved_at?->toDateTimeString(),
            'loan_amount' => (float) $row->loan_amount,
            'collection' => (float) $row->collection,
            'paid_amount' => (float) $row->paid_amount,
            'remain_amount' => (float) $row->remain_amount,
            'loan_status' => $row->loan_status,
            'monthly' => (object) ($row->monthly ?? []),
        ];
    }
}
