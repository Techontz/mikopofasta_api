<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The loans an eSIMU device has secured, one after another (application, top-up, replacement). An open link has no
 * unlinked_at; a device has at most one open link and a loan at most one open device.
 */
class PhoneBondDeviceLoan extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['linked_at' => 'datetime', 'unlinked_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PhoneBondDevice::class, 'device_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
