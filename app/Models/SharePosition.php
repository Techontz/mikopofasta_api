<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current shares held by one shareholder. Written only by the share register inside the database transaction that
 * records the movement (rows are locked FOR UPDATE), and always equal to a replay of the share transactions.
 */
class SharePosition extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shares' => 'integer',
            'first_acquired_at' => 'datetime',
        ];
    }

    public function shareHolder(): BelongsTo
    {
        return $this->belongsTo(ShareHolder::class);
    }
}
