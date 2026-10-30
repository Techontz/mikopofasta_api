<?php

namespace App\Models;

use App\Services\PhoneBond;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A phone enrolled with Linda as the security of a Phone Bond loan ({@see PhoneBond}). Active: enrolled, loan running.
 * Done: loan repaid, enrollment kept. Closed: removed from Linda through an authorised process (with the reason).
 */
class PhoneBondDevice extends Model
{
    public const ACTIVE = 'active';

    public const DONE = 'done';

    public const CLOSED = 'closed';

    public const LOCKED = 'locked';

    public const UNLOCKED = 'unlocked';

    public const LOCK_PENDING = 'lock_pending';

    public const UNLOCK_PENDING = 'unlock_pending';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sim_protection' => 'boolean', 'last_seen_at' => 'datetime', 'closed_at' => 'datetime'];
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

    public function requests(): HasMany
    {
        return $this->hasMany(PhoneBondLockRequest::class, 'device_id');
    }
}
