<?php

namespace App\Http\Resources\Api\V1\Customers;

use App\Models\CustomerDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Document resource (CUSTOMER_MODULE_IMPLEMENTATION.md §6). `downloadUrl` is relative to the API base.
 *
 * @mixin CustomerDocument
 */
class CustomerDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customerId' => (int) $this->customer_id,
            'documentType' => $this->document_type,
            'filePath' => $this->file_path,
            'originalName' => $this->original_name,
            'mimeType' => $this->mime_type,
            'sizeBytes' => (int) $this->size,
            'uploadedBy' => $this->uploaded_by === null ? null : (int) $this->uploaded_by,
            'uploadedByName' => $this->whenLoaded('uploader', fn () => $this->uploader?->full_name, null),
            'createdAt' => $this->created_at?->toIso8601String(),
            'downloadUrl' => "customers/{$this->customer_id}/documents/{$this->id}/download",
        ];
    }
}
