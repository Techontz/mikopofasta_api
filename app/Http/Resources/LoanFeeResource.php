<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoanFee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `LoanFeeSchema` in the frontend's types/loan-charge.ts.
 *
 * The product's level and interest ride along because the legacy screen shows
 * them on every fee row, and re-fetching the product list to render one table
 * would be a second round trip for data already in hand.
 *
 * @mixin LoanFee
 */
final class LoanFeeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'loanProductId' => (string) $this->loan_product_id,
            'feeType' => $this->fee_type->value,
            'feeTypeLabel' => $this->fee_type->label(),
            'feeAmount' => $this->fee_amount,
            'insuranceAmount' => $this->insurance_amount,
            'deletedAt' => $this->deleted_at?->toIso8601String(),

            'productName' => $this->whenLoaded('product', fn (): string => $this->product->name),
            'minAmount' => $this->whenLoaded('product', fn (): string => $this->product->min_amount),
            'maxAmount' => $this->whenLoaded('product', fn (): string => $this->product->max_amount),
            'interestRate' => $this->whenLoaded('product', fn (): string => $this->product->interest_rate),
        ];
    }
}
