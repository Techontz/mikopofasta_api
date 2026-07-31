<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Treasury\Enums\BankTransactionStatus;
use App\Domain\Treasury\Enums\BankTransactionType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A movement on a bank account — Bank → Bank Transaction / Approved
 * Transaction.
 *
 * Pending until someone decides it, and it posts nothing until then.
 *
 * @property int $id
 * @property string $reference
 * @property BankTransactionType $type
 * @property BankTransactionStatus $status
 * @property string $amount
 * @property string|null $note
 * @property int|null $journal_entry_id
 * @property CarbonImmutable $transacted_on
 * @property CarbonImmutable|null $decided_at
 */
class BankTransaction extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    public const LIST_RELATIONS = ['bankAccount', 'branch', 'requester', 'decider'];

    /** @var list<string> */
    protected $fillable = [
        'reference', 'bank_account_id', 'type', 'amount', 'branch_id', 'requested_by',
        'status', 'decided_by', 'decided_at', 'note', 'journal_entry_id', 'transacted_on',
    ];

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function amount(): Money
    {
        return Money::of($this->amount);
    }

    /**
     * @param Builder<BankTransaction> $query
     * @return Builder<BankTransaction>
     */
    public function scopeWithListRelations(Builder $query): Builder
    {
        return $query->with(self::LIST_RELATIONS);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => BankTransactionType::class,
            'status' => BankTransactionStatus::class,
            'amount' => 'decimal:2',
            'transacted_on' => 'immutable_date',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
