<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin-managed lookup list — see MasterDataModel.
 *
 * The one list with a column of its own: `document_type_id` says which document
 * evidences this identity type, so the registration form can name the upload
 * slot instead of leaving the officer to guess which of a dozen document types
 * their answer meant. See the 2026_09_04 migration.
 *
 * @property int|null $document_type_id
 */
final class IdType extends MasterDataModel
{
    protected $table = 'id_types';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code', 'name', 'description', 'sort_order', 'is_active', 'created_by',
        'document_type_id',
    ];

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }
}
