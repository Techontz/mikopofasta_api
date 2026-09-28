<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of an {@see Asset}: created, contributed as capital, allocated, transferred, revalued, status
 * changes, edits, documents and reversal. Rows are never updated or deleted.
 */
class AssetEvent extends Model
{
    public const EVENTS = [
        'created', 'contributed_as_capital', 'allocated_to_branch', 'transferred', 'revalued', 'maintenance', 'disposed',
        'written_off', 'status_changed', 'updated', 'document_uploaded', 'document_deleted', 'contribution_reversed',
    ];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'previous_value' => 'array',
            'new_value' => 'array',
            'amount_before' => 'decimal:2',
            'amount_after' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Asset history is append-only.'));
        static::deleting(fn () => throw new LogicException('Asset history is append-only.'));
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
