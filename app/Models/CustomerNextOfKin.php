<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerNextOfKin extends Model
{
    protected $table = 'customer_next_of_kin';

    protected $guarded = ['id'];

    /**
     * Production MySQL returns integer columns as strings; NextOfKinController compares customer_id strictly.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
