<?php

namespace App\Http\Resources\Api\V1\MasterData;

use App\Models\MasterData\MasterDataModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One master-data row: { id, code, name, description, sortOrder, isActive, parentId? }.
 *
 * @mixin MasterDataModel
 */
class MasterDataItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $parentColumn = $this->resource::PARENT_COLUMN;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'sortOrder' => (int) $this->sort_order,
            'isActive' => (bool) $this->is_active,
            ...($parentColumn === null ? [] : ['parentId' => (int) $this->{$parentColumn}]),
        ];
    }
}
