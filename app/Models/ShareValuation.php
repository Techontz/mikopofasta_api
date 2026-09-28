<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One share value event. History is never overwritten: a new value is a new row, and a wrong entry is marked reversed
 * (its figures stay as recorded). The value effective on a date is the latest effective valuation dated on or before it.
 * A valuation is a memorandum record only — it never posts to the ledger and is not cash.
 */
class ShareValuation extends Model
{
    use Auditable;

    public const EFFECTIVE = 'effective';

    public const REVERSED = 'reversed';

    /**
     * Columns a reversal may set; everything else is immutable.
     *
     * @var list<string>
     */
    private const REVERSAL_COLUMNS = ['status', 'reversed_at', 'reversed_by', 'reversal_reason', 'updated_at'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_value' => 'decimal:2',
            'new_value' => 'decimal:2',
            'valuation_date' => 'date',
            'total_shares' => 'integer',
            'previous_total_valuation' => 'decimal:2',
            'new_total_valuation' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ShareValuation $valuation): void {
            if (array_diff(array_keys($valuation->getDirty()), self::REVERSAL_COLUMNS) !== []) {
                throw new LogicException('Share valuations are never overwritten; record a new valuation instead.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Share valuations cannot be deleted.'));
    }

    /**
     * @param  Builder<ShareValuation>  $query
     */
    public function scopeEffective(Builder $query): void
    {
        $query->where('status', self::EFFECTIVE);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'performed_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reversed_by');
    }
}
