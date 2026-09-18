<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One collection against a customer salary advance.
 *
 * This row and its journal entry are written in the same transaction, and the
 * three portions here are the three credits that entry posted. That is what
 * makes the Salary Advance Payments figure on the dashboard a summary of the
 * ledger rather than a second opinion about it.
 *
 * @property int $id
 * @property string $reference
 * @property int $customer_advance_id
 * @property int|null $branch_id
 * @property string $amount
 * @property string $principal_portion
 * @property string $interest_portion
 * @property string $fee_portion
 * @property string $channel
 * @property string|null $note
 * @property CarbonImmutable $paid_at
 * @property int|null $received_by
 * @property int|null $journal_entry_id
 * @property CarbonImmutable|null $reversed_at
 * @property int|null $reversal_entry_id
 */
class CustomerAdvancePayment extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'reference', 'customer_advance_id', 'branch_id', 'amount',
        'principal_portion', 'interest_portion', 'fee_portion',
        'channel', 'note', 'paid_at', 'received_by', 'journal_entry_id',
        'reversed_at', 'reversal_entry_id',
    ];

    /** @return BelongsTo<CustomerAdvance, $this> */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(CustomerAdvance::class, 'customer_advance_id');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    /**
     * Payments that still count. A reversed row stays in the history but is
     * money that went back, so no total, summary or report may add it.
     *
     * @param Builder<self> $query
     */
    public function scopeNotReversed(Builder $query): void
    {
        $query->whereNull('reversed_at');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    public function principalMoney(): Money
    {
        return Money::of($this->principal_portion);
    }

    /** Interest and charge fee together — what the client calls the profit. */
    public function profitMoney(): Money
    {
        return Money::of($this->interest_portion)->add(Money::of($this->fee_portion));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'principal_portion' => 'decimal:2',
            'interest_portion' => 'decimal:2',
            'fee_portion' => 'decimal:2',
            'paid_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }
}
