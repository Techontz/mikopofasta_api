<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Treasury\Enums\FloatTransferKind;
use App\Domain\Treasury\Enums\FloatTransferStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money moving between the company's own accounts — the three float screens.
 *
 * `from_account_id`/`to_account_id` are what actually move; the branch columns
 * are what the screens display. A pending row has no journal entry: money moves
 * on approval, not on request.
 *
 * @property int $id
 * @property FloatTransferKind $kind
 * @property FloatTransferStatus $status
 * @property string $amount
 * @property int|null $journal_entry_id
 * @property int $requested_by
 * @property CarbonImmutable|null $approved_at
 */
class FloatTransfer extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'kind', 'from_branch_id', 'to_branch_id', 'from_account_id', 'to_account_id',
        'amount', 'status', 'requested_by', 'approved_by', 'approved_at', 'rejection_reason', 'journal_entry_id',
    ];

    /** @return BelongsTo<Branch, $this> */
    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    /** @return BelongsTo<ChartOfAccount, $this> */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'from_account_id');
    }

    /** @return BelongsTo<ChartOfAccount, $this> */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'to_account_id');
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => FloatTransferKind::class,
            'status' => FloatTransferStatus::class,
            'amount' => 'decimal:2',
            'approved_at' => 'immutable_datetime',
        ];
    }
}
