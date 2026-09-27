<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Balanced, immutable journal entry. Corrections are made with a reversal entry.
 */
class JournalEntry extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['entry_date' => 'date', 'transaction_type' => TransactionType::class];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Journal entries are immutable; post a reversal instead.'));
        static::deleting(fn (): never => throw new LogicException('Journal entries cannot be deleted; post a reversal instead.'));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
