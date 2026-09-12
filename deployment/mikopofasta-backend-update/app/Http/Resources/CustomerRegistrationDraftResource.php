<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerRegistrationDraft;
use App\Support\JsonRecord;
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
     * The payload keys that are RECORDS rather than lists.
     *
     * A draft is the wizard's own body, stored verbatim and handed back to
     * repopulate the form — so it has to come back in the shape it went out in.
     * PHP cannot keep that promise on its own: an empty record decodes to an
     * empty array and re-encodes as `[]`, and the wizard's schema then refuses
     * the resumed draft with "expected record, received array". It fails only
     * when the record is EMPTY, which is the ordinary case — a customer type
     * whose configured fields all write to real columns leaves this map empty —
     * so the bug hid behind every draft that happened to have data in it.
     *
     * Naming the keys rather than guessing from content: `guarantors` is a list
     * and is empty just as often, and forcing every empty array to an object
     * would break it in the opposite direction.
     *
     * @var list<string>
     */
    private const array RECORD_KEYS = ['dynamicFormData'];

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
            'payload' => $this->when(
                $request->routeIs('*customer-drafts.show'),
                fn (): array => self::withRecordShapes($this->payload),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function withRecordShapes(array $payload): array
    {
        foreach (self::RECORD_KEYS as $key) {
            if (array_key_exists($key, $payload) && is_array($payload[$key])) {
                $payload[$key] = JsonRecord::from($payload[$key]);
            }
        }

        return $payload;
    }
}
