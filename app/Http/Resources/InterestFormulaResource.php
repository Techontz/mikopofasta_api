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
            'code' => $this->code,
            'description' => $this->description,

            /*
             * Which formula a new product should start on — client Decision 2.
             *
             * Served rather than hardcoded in the form, so changing the default
             * is a row update and the two sides cannot disagree about what the
             * default is.
             */
            'isDefault' => (bool) $this->is_default,

            'deletedAt' => $this->deleted_at?->toIso8601String(),

            // How many products compute interest this way. Settings → Interest
            // Formulas shows it so an edit to a description carries the weight
            // of what it is describing.
            'productCount' => $this->whenCounted('products'),
        ];
    }
}
