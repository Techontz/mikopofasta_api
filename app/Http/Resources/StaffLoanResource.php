<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Hr\Services\StaffLoanCalculator;
use App\Models\StaffLoan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `StaffLoanSchema` in the frontend's types/staff.ts, with the recovery
 * figures alongside — the schema was written when a staff loan had an amount
 * and nothing else, and a screen cannot say what is left to repay without them.
 *
 * `disbursedAt` and `journalEntryId` became nullable in Module 7: a requested
 * loan has moved no money, so there is nothing yet to date or to point at.
 *
 * @mixin StaffLoan
 */
final class StaffLoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $calculator = app(StaffLoanCalculator::class);

        return [
            'id' => (string) $this->id,
            'reference' => $this->reference,
            'staffProfileId' => (string) $this->staff_profile_id,
            'amount' => $this->amount,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'disbursedAt' => $this->disbursed_at?->toDateString(),
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,

            'amountRecovered' => $this->amount_recovered,
            'recoveryPeriods' => $this->recovery_periods,

            /*
             * What is still owed and what the next payslip will take, from the
             * one calculator payroll itself uses. Derived here rather than in
             * the browser because the server is what decides the instalment —
             * and because the cap that stops an almost-cleared loan being
             * over-recovered lives in that calculator.
             */
            'outstanding' => $calculator->outstanding($this->resource)->toDecimalString(),
            'nextRecovery' => $calculator->recoveryFor($this->resource)->toDecimalString(),

            'requestedAt' => $this->requested_at?->toIso8601String(),
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'closedAt' => $this->closed_at?->toIso8601String(),
            'rejectionReason' => $this->rejection_reason,

            'staffName' => $this->whenLoaded('staffProfile', fn (): string => $this->staffProfile->displayName()),
            'approvedByName' => $this->whenLoaded('approver', fn (): ?string => $this->approver?->name),
        ];
    }
}
