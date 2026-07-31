<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Hr\Services\SalaryAdvanceCalculator;
use App\Models\StaffAdvance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `SalaryAdvanceSchema` in the frontend's types/salary-advance.ts —
 * the record all six Salary Advance screens read, each showing the columns its
 * stage cares about.
 *
 * Two naming notes, both following the frontend rather than correcting it:
 *
 *   `customerName` holds the **employee's** name. The legacy screens reuse the
 *   customer table's markup for staff, and the schema kept the column name. It
 *   is the staff member, not a customer.
 *
 *   `status` is the frontend's vocabulary — `active` and `repaid` where the
 *   backend enum says `disbursed` and `recovered`. Mapped here rather than
 *   renaming the enum, because §11 describes the backend lifecycle in those
 *   words and six screens are already written against these.
 *
 * @mixin StaffAdvance
 */
final class StaffAdvanceDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $calculator = app(SalaryAdvanceCalculator::class);
        $staff = $this->staffProfile;

        return [
            'id' => (string) $this->id,
            'reference' => $this->reference,

            'customerName' => $staff?->displayName() ?? '',
            // Keyed off the foreign key: the relation accessor is typed
            // non-null, so `?->x ?? ''` reads as an unreachable fallback.
            'phone' => $staff !== null && $staff->user_id !== null ? $staff->user->phone : '',
            'branch' => $staff !== null && $staff->branch_id !== null ? $staff->branch->name : '',

            'categoryId' => $this->salary_advance_category_id === null
                ? ''
                : (string) $this->salary_advance_category_id,
            'categoryName' => $this->whenLoaded(
                'category',
                fn (): string => $this->salary_advance_category_id === null ? '' : $this->category->name,
                '',
            ),

            'loanAmount' => $this->amount,
            // Money, not a rate — the legacy screens print it as a figure.
            'interest' => $this->interest_amount,
            'chargeFee' => $this->charge_fee,
            'paidAmount' => $this->amount_recovered,

            'status' => $this->status->frontendValue(),
            'date' => $this->requested_at->toDateString(),
            'overdueDays' => $this->overdueDays(),

            /*
             * Derived, never stored — the frontend computes the same two from
             * the columns above via `advanceTotals`, and both sides agreeing is
             * what stops a row disagreeing with its own footer.
             */
            'totalRepayable' => $calculator->totalRepayable($this->resource)->toDecimalString(),
            'remaining' => $calculator->outstanding($this->resource)->toDecimalString(),

            'recoveryPeriods' => $this->recovery_periods,
            'staffProfileId' => (string) $this->staff_profile_id,
            'branchId' => $staff?->branch_id === null ? null : (string) $staff->branch_id,
            'dueDate' => $this->due_date?->toDateString(),
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'disbursedAt' => $this->disbursed_at?->toIso8601String(),
            'recoveredAt' => $this->recovered_at?->toIso8601String(),
            'rejectionReason' => $this->rejection_reason,
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,
        ];
    }
}
