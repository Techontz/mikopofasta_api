<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Enums\ReserveUtilisationPurpose;
use App\Domain\Accounting\Enums\ReserveUtilisationStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A request to spend Reserve — Decision Register D1.
 *
 * "Reserve transfers require Admin approval. Branches cannot directly use
 * Reserve funds. Reserve belongs to Headquarters / Administration."
 *
 * A pending row has no journal entry: reserve moves on approval, not on
 * request, so a queue of proposals never touches the trial balance. The same
 * shape `float_transfers` uses, for the same reason.
 *
 * @property int $id
 * @property string $reference
 * @property ReserveUtilisationPurpose $purpose
 * @property string $amount
 * @property string $narrative
 * @property int|null $target_branch_id
 * @property ReserveUtilisationStatus $status
 * @property int $requested_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $decision_reason
 * @property int|null $journal_entry_id
 */
class ReserveUtilisation extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'reference', 'purpose', 'amount', 'narrative',
        'target_branch_id', 'status',
        'requested_by', 'approved_by', 'approved_at', 'decision_reason', 'journal_entry_id',
    ];

    /** @return BelongsTo<Branch, $this> */
    public function targetBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'target_branch_id');
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

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    /**
     * The next reference, derived from the highest existing one.
     *
     * From MAX rather than a row count, the same as every other generator here:
     * a soft-deleted request must not let its number be reissued to a different
     * request.
     */
    public static function nextReference(): string
    {
        $highest = (int) static::query()
            ->withTrashed()
            ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(reference, 4) AS UNSIGNED)), 0) AS seq')
            ->value('seq');

        return 'RU-'.str_pad((string) ($highest + 1), 7, '0', STR_PAD_LEFT);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => ReserveUtilisationPurpose::class,
            'status' => ReserveUtilisationStatus::class,
            'approved_at' => 'datetime',
        ];
    }
}
