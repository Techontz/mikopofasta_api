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
            /*
             * ID Types alone carry this — which document evidences the identity
             * type — and every other list is served by the same resource. Keyed
             * on the attribute being present rather than on the class, so a
             * list without the column omits the field instead of throwing under
             * `Model::shouldBeStrict()`.
             */
            'documentTypeId' => $this->when(
                array_key_exists('document_type_id', $this->resource->getAttributes()),
                fn (): ?string => $this->resource->getAttribute('document_type_id') === null
                    ? null
                    : (string) $this->resource->getAttribute('document_type_id'),
            ),
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
