<?php

namespace App\Http\Resources\Api\V1\Customers;

use App\Models\CustomerRegistrationDraft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Draft resource (CUSTOMER_MODULE_IMPLEMENTATION.md §3.2). The payload is included only on single-draft responses.
 *
 * @mixin CustomerRegistrationDraft
 */
class CustomerRegistrationDraftResource extends JsonResource
{
    public bool $withPayload = true;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'phone' => $this->phone,
            'step' => (int) $this->step,
            'branchId' => (int) $this->branch_id,
            'branchName' => $this->whenLoaded('branch', fn () => $this->branch?->name, null),
            'createdById' => (int) $this->created_by,
            'createdByName' => $this->whenLoaded('creator', fn () => $this->creator?->full_name, null),
            'isOwn' => (int) $this->created_by === (int) $request->user()?->id,
            'customerId' => $this->customer_id === null ? null : (int) $this->customer_id,
            'submittedAt' => $this->submitted_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'payload' => $this->when($this->withPayload, fn () => (object) ($this->payload ?? [])),
        ];
    }

    public function withoutPayload(): static
    {
        $this->withPayload = false;

        return $this;
    }
}
