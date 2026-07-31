<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Expenses\Enums\ExpenseRequestStatus;
use App\Domain\Expenses\Enums\ExpenseScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One filed expense — the record behind all four claim screens.
 *
 * A pending row has no `journal_entry_id`; approval is what posts the cost.
 *
 * @property int $id
 * @property string $reference
 * @property ExpenseScope $scope
 * @property ExpenseRequestStatus $status
 * @property string $amount
 * @property string $description
 * @property string|null $comment
 * @property int|null $journal_entry_id
 * @property CarbonImmutable $requested_on
 * @property CarbonImmutable|null $decided_at
 */
class ExpenseRequest extends Model
{
    use SoftDeletes;

    /**
     * The relations every list and detail response reads.
     *
     * Each row prints its category, its branch and the two people involved, so
     * naming them once keeps a screen of fifty rows at five queries rather than
     * two hundred — and keeps the list endpoint and the single-record one from
     * drifting into returning different shapes.
     *
     * @var list<string>
     */
    public const LIST_RELATIONS = ['category', 'branch', 'bankAccount', 'requester', 'decider'];

    /** @var list<string> */
    protected $fillable = [
        'reference', 'expense_category_id', 'scope', 'branch_id', 'bank_account_id', 'requested_by',
        'amount', 'description', 'comment', 'status', 'decided_by', 'decided_at',
        'journal_entry_id', 'requested_on',
    ];

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id')->withTrashed();
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The bank account this expense was paid from, when it was not paid in
     * cash — Bank → Register Bank Expenses.
     *
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
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

    /**
     * @param Builder<ExpenseRequest> $query
     * @return Builder<ExpenseRequest>
     */
    public function scopeWithListRelations(Builder $query): Builder
    {
        return $query->with(self::LIST_RELATIONS);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope' => ExpenseScope::class,
            'status' => ExpenseRequestStatus::class,
            'amount' => 'decimal:2',
            'requested_on' => 'immutable_date',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
