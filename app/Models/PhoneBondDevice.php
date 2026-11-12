<?php

namespace App\Models;

use App\Services\PhoneBond;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An eSIMU smartphone (the Phone Bond loan method) managed by Linda Device Lock ({@see PhoneBond}).
 *
 * status (the Devices tabs):
 *  - pending: being registered - waiting for Linda (draft) or for the customer to scan the enrollment QR; never collateral;
 *  - active: enrolled and under Linda management, with or without a running loan;
 *  - done: the loan it secured is settled; still enrolled with Linda and reusable for a later eSIMU loan;
 *  - closed: removed from Linda management (released in the Linda portal), kept for history.
 *
 * enrollment_status: not_registered (Linda not reached yet), pending (registered, QR not scanned), enrolled (Linda confirms).
 * lock_state changes only on Linda's confirmation; *_pending while a command waits for it.
 * condition: good, damaged, lost or stolen - only a good device may secure a new loan.
 */
class PhoneBondDevice extends Model
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const DONE = 'done';

    public const CLOSED = 'closed';

    public const LOCKED = 'locked';

    public const UNLOCKED = 'unlocked';

    public const LOCK_PENDING = 'lock_pending';

    public const UNLOCK_PENDING = 'unlock_pending';

    public const NOT_REGISTERED = 'not_registered';

    public const ENROLLMENT_PENDING = 'pending';

    public const ENROLLED = 'enrolled';

    public const GOOD = 'good';

    /**
     * @var list<string>
     */
    public const CONDITIONS = ['good', 'damaged', 'lost', 'stolen'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sim_protection' => 'boolean',
            'last_seen_at' => 'datetime',
            'closed_at' => 'datetime',
            'enrollment_payload' => 'array',
            'enrollment_expires_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'application_draft' => 'array',
            'inspected_at' => 'datetime',
        ];
    }

    public function isEnrolled(): bool
    {
        return $this->enrollment_status === self::ENROLLED;
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'registered_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'closed_by');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(PhoneBondLockRequest::class, 'device_id');
    }

    public function loanLinks(): HasMany
    {
        return $this->hasMany(PhoneBondDeviceLoan::class, 'device_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(PhoneBondIncident::class, 'device_id');
    }
}
