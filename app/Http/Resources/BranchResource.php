<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `BranchSchema` in the frontend's types/branch.ts, which validates
 * this payload with Zod: camelCase keys, and every id a STRING.
 *
 * @mixin Branch
 */
final class BranchResource extends JsonResource
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
            'regionId' => self::id($this->region_id),
            'zoneId' => self::id($this->zone_id),
            'phone' => $this->phone,
            'type' => $this->type->value,
            'parentBranchId' => self::id($this->parent_branch_id),
            'isHeadOffice' => $this->is_head_office,
            'status' => $this->status->value,
            'createdBy' => self::id($this->created_by),
            'deletedAt' => $this->deleted_at?->toIso8601String(),

            /*
             * Display names, present only when the caller eager-loaded them.
             * whenLoaded keeps this resource from silently issuing a query per
             * row — with Model::shouldBeStrict() active it would throw instead,
             * which is the point.
             */
            'regionName' => $this->whenLoaded('region', fn (): ?string => $this->region?->name),
            'zoneName' => $this->whenLoaded('zone', fn (): ?string => $this->zone?->name),
            'parentBranchName' => $this->whenLoaded('parent', fn (): ?string => $this->parent?->name),
        ];
    }

    private static function id(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
