<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Treasury\Enums\BankTransferKind;
use App\Domain\Treasury\Enums\BankTransferStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money moved out of a bank account — the two Transfer Balance screens.
 *
 * Exactly one destination is set: `to_branch_id` for a branch transfer,
 * `to_account_id` for a salary-advance one. `charge_fee` is the bank's own
 * charge and is posted separately rather than netted off the amount, so the
 * destination receives what was sent and the cost of sending it stays visible.
 *
 * @property int $id
 * @property string $reference
 * @property BankTransferKind $kind
 * @property BankTransferStatus $status
 * @property string $amount
 * @property string $charge_fee
 * @property string $reason
 * @property int|null $journal_entry_id
 * @property CarbonImmutable $transferred_on
 */
class BankTransfer extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    public const LIST_RELATIONS = ['fromAccount', 'toAccount', 'toBranch', 'requester'];

    /** @var list<string> */
    protected $fillable = [
        'reference', 'kind', 'from_account_id', 'to_account_id', 'to_branch_id',
        'amount', 'charge_fee', 'reason', 'description', 'requested_by',
        'status', 'journal_entry_id', 'transferred_on',
    ];

    /** @return BelongsTo<BankAccount, $this> */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'from_account_id');
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'to_account_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function amount(): Money
    {
        return Money::of($this->amount);
    }

    public function chargeFee(): Money
    {
        return Money::of($this->charge_fee);
    }

    /** What actually leaves the source account: the transfer plus the charge. */
    public function totalDebited(): Money
    {
        return $this->amount()->add($this->chargeFee());
    }

    /**
     * @param Builder<BankTransfer> $query
     * @return Builder<BankTransfer>
     */
    public function scopeWithListRelations(Builder $query): Builder
    {
        return $query->with(self::LIST_RELATIONS);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => BankTransferKind::class,
            'status' => BankTransferStatus::class,
            'amount' => 'decimal:2',
            'charge_fee' => 'decimal:2',
            'transferred_on' => 'immutable_date',
        ];
    }
}
