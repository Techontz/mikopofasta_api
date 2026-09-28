<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money received from a customer through any channel: teller cash, direct (webhook) payment,
 * or an unmatched receipt held in suspense. Loan postings are recorded as PaymentAllocation rows.
 */
class Payment extends Model
{
    use Auditable;

    public const SOURCE_TELLER = 'teller';

    public const SOURCE_WEBHOOK = 'webhook';

    public const SOURCE_MANUAL = 'manual';

    protected $guarded = ['id'];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'allocated_amount' => 'decimal:2',
            'paid_on' => 'date',
            'verified_at' => 'datetime',
            'payload' => 'array',
            'status' => PaymentStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'verified_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function tellerDeposit(): BelongsTo
    {
        return $this->belongsTo(TellerDeposit::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * Amount still held in suspense (not yet posted to a loan).
     */
    protected function unallocatedAmount(): Attribute
    {
        return Attribute::get(fn (): float => max(0.0, round((float) $this->amount - (float) $this->allocated_amount, 2)));
    }
}
