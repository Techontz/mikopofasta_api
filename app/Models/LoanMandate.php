<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bank e-mandate authorising automatic repayment deductions (mandate loan products).
 */
class LoanMandate extends Model
{
    public const PENDING_OTP = 'pending_otp';

    public const FAILED = 'failed';

    public const ACTIVE = 'active';

    /** A replaced mandate whose cancellation was asked of the bank, not yet confirmed (enhancement §3.4). */
    public const CANCELLATION_REQUESTED = 'requested';

    public const CANCELLATION_CONFIRMED = 'confirmed';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'cancellation_requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
