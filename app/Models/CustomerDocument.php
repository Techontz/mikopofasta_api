<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.4 — `customer_documents`.
 *
 * `file_path` points at the PRIVATE `kyc` disk and must never leave the
 * application. CustomerDocumentResource emits a signed, expiring download URL
 * in its place.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $document_type
 * @property string $file_path
 * @property string|null $original_name
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property int|null $uploaded_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CustomerDocument extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id', 'document_type', 'file_path',
        'original_name', 'mime_type', 'size_bytes', 'uploaded_by',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
