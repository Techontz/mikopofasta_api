<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The amount a historical File report row shows under one month column. A figure from the printed report, not a
 * repayment: it has no receipt, no loan transaction and no journal entry.
 */
class HistoricalFilePayment extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<HistoricalFileRecord, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(HistoricalFileRecord::class, 'historical_file_record_id');
    }
}
