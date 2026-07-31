<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Guarantor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `GuarantorSchema` in the frontend's types/guarantor.ts.
 *
 * @mixin Guarantor
 */
final class GuarantorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'customerId' => (string) $this->customer_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'nidaNumber' => $this->nida_number,
            'relationship' => $this->relationship->value,
            'address' => $this->address,
            'occupation' => $this->occupation,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
