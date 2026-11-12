<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lock or unlock asked of Linda (older rows may be top-up debt updates). Linda's commands are asynchronous:
 *  - pending: Linda accepted it (command QUEUED / SENT), or it could not be delivered yet and is retried with the same
 *    idempotency key;
 *  - confirmed: the phone confirmed it (webhook or command status ACKED), or staff recorded Linda's portal reference;
 *  - failed: Linda refused it or the phone reported it could not carry it out. The device is left as it was.
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
        return [
            'provider_response' => 'array',
            'confirmed_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'alerted_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PhoneBondDevice::class, 'device_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'confirmed_by');
    }
}
