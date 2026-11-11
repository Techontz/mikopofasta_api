<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lost, stolen or damaged report on an eSIMU device. It records what happened; the loan and its debt are unchanged.
 */
class PhoneBondIncident extends Model
{
    /**
     * @var list<string>
     */
    public const TYPES = ['lost', 'stolen', 'damaged'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PhoneBondDevice::class, 'device_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reported_by');
    }
}
