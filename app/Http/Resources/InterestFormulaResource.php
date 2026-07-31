<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\InterestFormula;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `InterestFormulaSchema` in the frontend's types/loan-product.ts.
 *
 * @mixin InterestFormula
 */
final class InterestFormulaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'code' => $this->code->value,
            'description' => $this->description,
            'deletedAt' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
