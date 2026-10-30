<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lock, unlock or debt update asked of Linda. `confirmed` only with Linda's confirmation (from the service, or its
 * reference recorded by authorised staff); `pending` while Linda has accepted but not confirmed; `failed` when refused.
 */
class PhoneBondLockRequest extends Model
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['provider_response' => 'array', 'confirmed_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PhoneBondDevice::class, 'device_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }
}
