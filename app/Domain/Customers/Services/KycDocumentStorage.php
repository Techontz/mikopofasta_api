<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Models\Customer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The only code path that touches KYC files on disk.
 *
 * Spec §1: KYC documents live on a private disk, "signed, time-limited URLs
 * only, never public disk". Two rules follow, and both are enforced here
 * rather than left to callers:
 *
 *   1. Every write goes to the `kyc` disk, which is `visibility: private`,
 *      `serve: false`, and is never symlinked into public/.
 *   2. The stored path never leaves the application. Resources emit a signed,
 *      expiring URL to the download endpoint; the path itself is an internal
 *      detail, and exposing it would let a client reason about the layout of
 *      the private disk even if it could not read it.
 *
 * Filenames are generated, never taken from the upload. A user-supplied name
 * is attacker-controlled and is the classic path-traversal vector; the
 * original is kept as a column for display only.
 */
final class KycDocumentStorage
{
    public const string DISK = 'kyc';

    /**
     * How long a generated download link stays valid. Short by design — the
     * link is handed to a browser that is about to use it, not stored.
     */
    public const int URL_TTL_MINUTES = 5;

    /**
     * Stores an uploaded document and returns its path on the private disk.
     */
    public function store(Customer $customer, UploadedFile $file, string $documentType): string
    {
        $extension = $file->extension() ?: $file->getClientOriginalExtension() ?: 'bin';

        $filename = sprintf(
            '%s-%s.%s',
            Str::slug($documentType) ?: 'document',
            Str::random(24),
            $extension,
        );

        $path = $file->storeAs(
            $this->directoryFor($customer),
            $filename,
            ['disk' => self::DISK],
        );

        return (string) $path;
    }

    public function delete(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }

    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }

    /**
     * @return resource|null
     */
    public function readStream(string $path)
    {
        return Storage::disk(self::DISK)->readStream($path);
    }

    public function size(string $path): int
    {
        return (int) Storage::disk(self::DISK)->size($path);
    }

    /**
     * Files are grouped per customer so a customer's KYC bundle can be
     * located, audited or purged as a unit.
     */
    private function directoryFor(Customer $customer): string
    {
        return 'customers/'.$customer->getKey();
    }
}
