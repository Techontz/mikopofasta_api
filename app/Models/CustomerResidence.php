<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current residence selected from the Mkoa → Wilaya → Kata → Mtaa lists, plus owned / rented.
 */
class CustomerResidence extends Model
{
    public const OWNERSHIP = ['owned' => 'Nimejenga / Owned', 'rented' => 'Nimepanga / Rented'];

    protected $guarded = ['id'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
