<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to replace the phone securing a running eSIMU loan (lost, stolen or damaged). It changes nothing until it is
 * approved: then the new, already enrolled phone secures the same loan and the old one leaves it. The loan, its balance
 * and its schedule are never touched.
 */
class PhoneBondReplacement extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    public const REASON_TYPES = ['lost', 'stolen', 'damaged', 'other'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function oldDevice(): BelongsTo
    {
        return $this->belongsTo(PhoneBondDevice::class, 'old_device_id');
    }

    public function newDevice(): BelongsTo
    {
        return $this->belongsTo(PhoneBondDevice::class, 'new_device_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'decided_by');
    }
}
