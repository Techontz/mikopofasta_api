<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Customers\Services\KycDocumentStorage;
use App\Models\CustomerDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Matches `CustomerDocumentSchema` in the frontend's types/customer.ts.
 *
 * `filePath` carries a signed, expiring download URL — never the path on
 * disk. Spec §1 requires "signed, time-limited URLs only" for KYC documents,
 * and even a private path is information about the layout of regulated
 * storage. The frontend's schema types this as a string and its documents
 * panel does not parse it, so a URL satisfies the contract exactly.
 *
 * @mixin CustomerDocument
 */
final class CustomerDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'customerId' => (string) $this->customer_id,
            'documentType' => $this->document_type,

            'filePath' => URL::temporarySignedRoute(
                'api.v1.customers.documents.download',
                now()->addMinutes(KycDocumentStorage::URL_TTL_MINUTES),
                ['customer' => $this->customer_id, 'document' => $this->id],
            ),

            'originalName' => $this->original_name,
            'mimeType' => $this->mime_type,
            'sizeBytes' => $this->size_bytes,
            'uploadedBy' => $this->uploaded_by === null ? null : (string) $this->uploaded_by,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
