<?php

namespace App\Http\Resources\Api\V1\Shares;

use App\Models\ShareValuation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShareValuation
 */
class ShareValuationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $previous = $this->previous_value === null ? null : (float) $this->previous_value;
        $new = (float) $this->new_value;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'kind' => $this->kind,
            'previous_value' => $previous,
            'new_value' => $new,
            'change' => $previous === null ? null : round($new - $previous, 2),
            'change_percent' => $previous === null || $previous == 0.0 ? null : round(($new - $previous) / $previous * 100, 2),
            'valuation_date' => $this->valuation_date?->toDateString(),
            'total_shares' => $this->total_shares,
            'previous_total_valuation' => $this->previous_total_valuation === null ? null : (float) $this->previous_total_valuation,
            'new_total_valuation' => (float) $this->new_total_valuation,
            'reason' => $this->reason,
            'status' => $this->status,
            'performed_by' => $this->performer?->full_name,
            'reversed_at' => $this->reversed_at?->format('Y-m-d H:i:s'),
            'reversed_by' => $this->reverser?->full_name,
            'reversal_reason' => $this->reversal_reason,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
