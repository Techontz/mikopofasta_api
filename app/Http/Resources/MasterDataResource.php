<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MasterData\MasterDataModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One shape for all nine lookup lists.
 *
 * @mixin MasterDataModel
 */
final class MasterDataResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            /* The stable value data references. The label may be renamed; this
               may not, which is why both go out. */
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'sortOrder' => $this->sort_order,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
