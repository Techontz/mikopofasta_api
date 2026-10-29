<?php

namespace App\Models;

use App\Services\LoanDiscounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A loan discount / waiver request ({@see LoanDiscounts}): nothing changes on the loan until it is approved.
 */
class LoanDiscount extends Model
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
            'original_debt' => 'decimal:2',
            'actual_payment' => 'decimal:2',
            'requested_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'principal' => 'decimal:2',
            'interest' => 'decimal:2',
            'insurance' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
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

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'decided_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
