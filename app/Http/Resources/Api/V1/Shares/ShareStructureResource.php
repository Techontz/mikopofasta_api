<?php

namespace App\Http\Resources\Api\V1\Shares;

use App\Models\ShareStructure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShareStructure
 */
class ShareStructureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'authorised_shares' => $this->authorised_shares,
            'initial_capital_basis' => (float) $this->initial_capital_basis,
            'initial_shares' => $this->initial_shares,
            'initial_share_value' => (float) $this->initial_share_value,
            'established_on' => $this->established_on?->toDateString(),
            'notes' => $this->notes,
            'created_by' => $this->creator?->full_name,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
