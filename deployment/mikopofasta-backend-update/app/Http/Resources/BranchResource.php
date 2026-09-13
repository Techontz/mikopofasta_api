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
            /*
             * The Branch List's customer-status counts, present only when the
             * caller asked for them. Four mutually exclusive buckets plus the
             * branch total — a customer with no loan appears only in `all`.
             */
            /*
             * Guarded on the ATTRIBUTE BAG, not on a property read.
             * `Model::shouldBeStrict()` throws on an un-retrieved attribute, so
             * `$this->customers_all_count !== null` would 500 every endpoint
             * that returns a branch without asking for the counts — show,
             * update, the hierarchy tree. Asking whether the key was selected
             * is the question actually being asked.
             */
            'customerStatus' => $this->when(
                array_key_exists('customers_all_count', $this->resource->getAttributes()),
                fn (): array => [
                    'active' => (int) $this->customers_active_count,
                    'pending' => (int) $this->customers_pending_count,
                    'default' => (int) $this->customers_default_count,
                    'done' => (int) $this->customers_done_count,
                    'all' => (int) $this->customers_all_count,
                ],
            ),

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
