<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Repayments\Enums\PaymentChannel;
use App\Domain\Repayments\Enums\PaymentStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Backend spec §2.6 — `payments`.
 *
 * No soft delete: §2 lists this among the tables where deletion is
 * architecturally impossible. A payment that should not have happened is
 * reversed.
 *
 * @property int $id
 * @property string $payment_reference
 * @property int|null $loan_id
 * @property int|null $customer_id
 * @property string $amount
 * @property PaymentChannel $channel
 * @property string|null $transaction_id
 * @property PaymentStatus $status
 * @property int|null $branch_id
 * @property int|null $teller_id
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $created_by
 * @property int|null $journal_entry_id
 */
class Payment extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'payment_reference', 'loan_id', 'customer_id', 'amount', 'channel', 'transaction_id',
        'status', 'branch_id', 'teller_id', 'received_at', 'confirmed_at', 'created_by',
        'journal_entry_id',
    ];

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @return HasOne<SuspenseItem, $this>
     */
    public function suspenseItem(): HasOne
    {
        return $this->hasOne(SuspenseItem::class);
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => PaymentChannel::class,
            'status' => PaymentStatus::class,
            'received_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }
}
