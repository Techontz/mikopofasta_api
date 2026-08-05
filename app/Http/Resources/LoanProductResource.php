<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoanProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `LoanProductSchema` in the frontend's types/loan-product.ts.
 *
 * `allowedRepaymentScheduleIds` is the §2.3 pivot the loan application form
 * needs to know which cadences it may offer.
 *
 * @mixin LoanProduct
 */
final class LoanProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'interestFormulaId' => (string) $this->interest_formula_id,
            'interestRate' => $this->interest_rate,
            'minAmount' => $this->min_amount,
            'maxAmount' => $this->max_amount,
            'minTenureDays' => $this->min_tenure_days,
            'maxTenureDays' => $this->max_tenure_days,
            'penaltyType' => $this->penalty_type->value,
            'penaltyRate' => $this->penalty_rate,
            'penaltyGraceDays' => $this->penalty_grace_days,
            'penaltyCapAmount' => $this->penalty_cap_amount,
            'requiresMandate' => $this->requires_mandate,
            'status' => $this->status->value,
            'createdBy' => $this->created_by === null ? null : (string) $this->created_by,
            'deletedAt' => $this->deleted_at?->toIso8601String(),

            'interestFormulaCode' => $this->whenLoaded(
                'interestFormula',
                fn (): ?string => $this->interestFormula?->code,
            ),
            'allowedRepaymentScheduleIds' => $this->whenLoaded(
                'repaymentSchedules',
                fn (): array => $this->repaymentSchedules->map(fn ($s): string => (string) $s->getKey())->all(),
            ),
            'loanCount' => $this->whenCounted('loans'),
        ];
    }
}
