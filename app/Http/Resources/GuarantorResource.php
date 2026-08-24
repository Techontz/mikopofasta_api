<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Customers\Services\KycDocumentStorage;
use App\Models\Guarantor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Matches `GuarantorSchema` in the frontend's types/guarantor.ts.
 *
 * @mixin Guarantor
 */
final class GuarantorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'customerId' => (string) $this->customer_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'nidaNumber' => $this->nida_number,
            'gender' => $this->gender?->value,
            'maritalStatus' => $this->marital_status?->value,
            'relationship' => $this->relationship->value,
            'address' => $this->address,
            'occupation' => $this->occupation,
            /* Who this person already stands for. Only when the relation is
               loaded — the per-customer endpoints do not need it, and the
               import picker would be ambiguous without it: two guarantors can
               share a name, and the customer they belong to is what tells them
               apart. */
            'customerName' => $this->whenLoaded(
                'customer',
                fn (): ?string => $this->customer?->fullName(),
            ),
            'customerNumber' => $this->whenLoaded(
                'customer',
                fn (): ?string => $this->customer?->customer_number,
            ),
            /*
             * A signed, expiring URL — never the path on disk. Spec §1 requires
             * "signed, time-limited URLs only" for KYC documents, and even a
             * private path leaks the layout of regulated storage. Exactly what
             * CustomerDocumentResource does, and for the same reason.
             *
             * Null when there is no passport, so the client can tell "none on
             * file" from "here is where to fetch it" without parsing a URL.
             */
            'passportUrl' => $this->passport_path === null ? null : URL::temporarySignedRoute(
                'api.v1.guarantors.passport',
                now()->addMinutes(KycDocumentStorage::URL_TTL_MINUTES),
                ['guarantor' => $this->id],
            ),
            'passportName' => $this->passport_original_name,
            'passportMimeType' => $this->passport_mime_type,
            'passportSizeBytes' => $this->passport_size_bytes,

            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
