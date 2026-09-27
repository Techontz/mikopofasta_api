<?php

namespace App\Http\Resources\Api\V1\Expenses;

use App\Models\ExpenseType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExpenseType
 */
class ExpenseTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'name' => $this->name,
        ];
    }
}
