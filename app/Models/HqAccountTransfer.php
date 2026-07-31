<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Treasury\Enums\HqTransactionDirection;
use App\Domain\Treasury\Enums\HqTransactionStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A headquarters movement — the record behind all three Headquarters
 * Transaction screens.
 *
 * `direction` says what it does to the headquarters position: `in` and `out`
 * change the total, `internal` moves money between two of the seven pots and
 * leaves it alone. A one-sided movement names only the account it landed in or
 * came from; only an internal transfer has both.
 *
 * `staff_name` and `charger` are the legacy columns and are not written by this
 * application — they are where imported legacy rows will land. New records name
 * a real user in `requested_by`.
 *
 * @property int $id
 * @property string $reference
 * @property int|null $from_account_id
 * @property int|null $to_account_id
 * @property int|null $branch_id
 * @property string $amount
 * @property HqTransactionDirection $direction
 * @property string|null $reason
 * @property string|null $charger
 * @property string|null $staff_name
 * @property HqTransactionStatus $status
 * @property CarbonImmutable $requested_on
 * @property CarbonImmutable|null $approved_on
 */
class HqAccountTransfer extends Model
{
    use SoftDeletes;

    /**
     * What every list and detail response reads.
     *
     * @var list<string>
     */
    public const LIST_RELATIONS = ['fromAccount', 'toAccount', 'branch', 'requester', 'approver'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'from_account_id', 'to_account_id', 'branch_id', 'amount',
        'direction', 'reason', 'charger', 'staff_name', 'requested_by', 'approved_by',
        'status', 'requested_on', 'approved_on',
    ];

    /**
     * @return BelongsTo<HqAccount, $this>
     */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(HqAccount::class, 'from_account_id');
    }

    /**
     * @return BelongsTo<HqAccount, $this>
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(HqAccount::class, 'to_account_id');
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
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function amount(): Money
    {
        return Money::of($this->amount);
    }

    /**
     * @param Builder<HqAccountTransfer> $query
     * @return Builder<HqAccountTransfer>
     */
    public function scopeWithListRelations(Builder $query): Builder
    {
        return $query->with(self::LIST_RELATIONS);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'direction' => HqTransactionDirection::class,
            'status' => HqTransactionStatus::class,
            'requested_on' => 'immutable_date',
            'approved_on' => 'immutable_date',
        ];
    }
}
