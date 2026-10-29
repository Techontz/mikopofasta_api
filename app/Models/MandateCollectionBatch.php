<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One bank collection run of e-mandate debits (an uploaded collection file or a provider notification day).
 */
class MandateCollectionBatch extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['collection_date' => 'date', 'expected_total' => 'decimal:2', 'paid_total' => 'decimal:2'];
    }

    public function collections(): HasMany
    {
        return $this->hasMany(MandateCollection::class, 'batch_id');
    }
}
