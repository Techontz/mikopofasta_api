<?php

namespace App\Models;

use App\Services\Customers\CustomerUpdates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change to a registered customer's details ({@see CustomerUpdates}): made by branch staff it waits
 * here, `pending`, until CRM approves (applied) or rejects it; made by CRM or Admin it is applied at once and kept here as
 * `approved`, so Customer Updates lists every change with its old and new value.
 */
class CustomerUpdateRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'changes' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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
