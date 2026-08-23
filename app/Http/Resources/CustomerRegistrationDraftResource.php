<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerRegistrationDraft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An unfinished registration.
 *
 * `payload` is omitted from list responses and present on a single read. The
 * list is a picker — a name, a phone and when it was last touched — and
 * shipping a whole wizard payload per row would make choosing between three
 * drafts cost as much as opening all three.
 *
 * @mixin CustomerRegistrationDraft
 */
final class CustomerRegistrationDraftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->getKey(),
            'label' => $this->label,
            'phone' => $this->phone,
            'step' => $this->step,
            'branchId' => (string) $this->branch_id,
            'createdById' => (string) $this->created_by,
            'createdByName' => $this->whenLoaded('author', fn (): ?string => $this->author?->name),
            'customerId' => $this->customer_id === null ? null : (string) $this->customer_id,
            'submittedAt' => $this->submitted_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),

            /*
             * Only on a single read — see the class note. `whenNotNull` would
             * not do: the payload is never null, it is deliberately withheld.
             *
             * The wildcard is not decoration: route names in this application
             * carry an `api.v1.` prefix from the group, so an exact match here
             * silently never fires and the payload never reaches the wizard.
             */
            'payload' => $this->when($request->routeIs('*customer-drafts.show'), fn (): array => $this->payload),
        ];
    }
}
