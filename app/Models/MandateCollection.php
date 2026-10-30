<?php

namespace App\Models;

use App\Services\MandateCollections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One e-mandate debit reported by the bank ({@see MandateCollections}): recorded once per provider payment reference.
 */
class MandateCollection extends Model
{
    public const FULL = 'full';

    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    public const UNMATCHED = 'unmatched';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collection_date' => 'date',
            'expected_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'allocated_amount' => 'decimal:2',
            'payload' => 'array',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function mandate(): BelongsTo
    {
        return $this->belongsTo(LoanMandate::class, 'loan_mandate_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MandateCollectionBatch::class, 'batch_id');
    }
}
