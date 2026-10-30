<?php

namespace App\Models;

use App\Services\Customers\DuplicateCustomerCheck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registration held because it may belong to an existing customer ({@see DuplicateCustomerCheck}): the submitted
 * payload waits `pending` until a KYC approver releases it (the customer is registered) or rejects it as a duplicate.
 */
class CustomerDuplicateReview extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RELEASED = 'released';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'matches' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
