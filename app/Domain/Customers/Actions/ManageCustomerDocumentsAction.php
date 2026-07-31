<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Services\KycDocumentStorage;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Uploads and removes customer KYC documents.
 *
 * The file lands on the private disk BEFORE the transaction opens, and is
 * cleaned up by hand if the insert fails. Filesystem writes are not
 * transactional, so a rollback would otherwise leave an orphaned file on a
 * disk holding regulated data.
 */
final class ManageCustomerDocumentsAction
{
    public function __construct(
        private readonly KycDocumentStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function upload(Customer $customer, UploadedFile $file, string $documentType, User $actor): CustomerDocument
    {
        $path = $this->storage->store($customer, $file, $documentType);

        try {
            return DB::transaction(function () use ($customer, $file, $documentType, $path, $actor): CustomerDocument {
                $document = $customer->documents()->create([
                    'document_type' => $documentType,
                    'file_path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size_bytes' => $file->getSize(),
                    'uploaded_by' => $actor->getKey(),
                ]);

                $this->audit->log(
                    AuditAction::CustomerDocumentUploaded,
                    $customer,
                    after: ['document_type' => $documentType, 'document_id' => $document->getKey()],
                    actor: $actor,
                );

                return $document;
            });
        } catch (Throwable $e) {
            $this->storage->delete($path);

            throw $e;
        }
    }

    public function remove(CustomerDocument $document, User $actor): void
    {
        $customer = $document->customer;
        $path = $document->file_path;

        DB::transaction(function () use ($document, $customer, $actor): void {
            $this->audit->log(
                AuditAction::CustomerDocumentRemoved,
                $customer,
                before: ['document_type' => $document->document_type, 'document_id' => $document->getKey()],
                actor: $actor,
            );

            $document->delete();
        });

        // Only after the row is definitely gone: deleting the file first would
        // leave a record pointing at nothing if the transaction rolled back.
        $this->storage->delete($path);
    }
}
