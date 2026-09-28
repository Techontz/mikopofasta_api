<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\LoanService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rule 6 (maker/checker) for write-offs ({@see LoanService::requestWriteOff()}): a write-off is requested as PENDING — nothing
 * posted, the loan status unchanged — and posted only when a different authorised user approves it; rejection keeps the row.
 */
class WriteOffRequest extends Model
{
    use Auditable;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rejected_by');
    }

    public function writeOff(): BelongsTo
    {
        return $this->belongsTo(WriteOff::class);
    }
}
