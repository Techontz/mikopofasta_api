<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\CustomerAdvances\Enums\CustomerAdvanceStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Salary Advance (Customer) — `customer_advances`.
 *
 * The terms are snapshotted from the band at request, the same rule loans,
 * penalties and staff advances all follow. `principal_repaid`,
 * `interest_repaid` and `fee_repaid` are the cumulative credits the ledger has
 * taken, which is what lets the receivable close at exactly zero however the
 * customer chooses to pay.
 *
 * @property int $id
 * @property string $reference
 * @property int $customer_id
 * @property int|null $branch_id
 * @property int|null $salary_advance_category_id
 * @property string $amount
 * @property string $interest_amount
 * @property string $charge_fee
 * @property int $recovery_periods
 * @property string $amount_repaid
 * @property string $principal_repaid
 * @property string $interest_repaid
 * @property string $fee_repaid
 * @property CustomerAdvanceStatus $status
 * @property CarbonImmutable $requested_at
 * @property int|null $requested_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $disbursed_by
 * @property CarbonImmutable|null $disbursed_at
 * @property CarbonImmutable|null $settled_at
 * @property CarbonImmutable|null $due_date
 * @property string|null $rejection_reason
 * @property int|null $funding_account_id
 * @property int|null $journal_entry_id
 */
class CustomerAdvance extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    public const LIST_RELATIONS = ['customer', 'branch', 'category', 'approver'];

    /** @var list<string> */
    protected $fillable = [
        'reference', 'customer_id', 'branch_id', 'salary_advance_category_id',
        'amount', 'interest_amount', 'charge_fee', 'recovery_periods',
        'amount_repaid', 'principal_repaid', 'interest_repaid', 'fee_repaid',
        'status', 'requested_at', 'requested_by', 'approved_by', 'approved_at',
        'disbursed_by', 'disbursed_at', 'settled_at', 'due_date', 'rejection_reason',
        'funding_account_id', 'journal_entry_id',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<SalaryAdvanceCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SalaryAdvanceCategory::class, 'salary_advance_category_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return HasMany<CustomerAdvancePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(CustomerAdvancePayment::class);
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    public function interestMoney(): Money
    {
        return Money::of($this->interest_amount);
    }

    public function chargeFeeMoney(): Money
    {
        return Money::of($this->charge_fee);
    }

    public function repaidMoney(): Money
    {
        return Money::of($this->amount_repaid);
    }

    public function principalRepaidMoney(): Money
    {
        return Money::of($this->principal_repaid);
    }

    public function interestRepaidMoney(): Money
    {
        return Money::of($this->interest_repaid);
    }

    public function feeRepaidMoney(): Money
    {
        return Money::of($this->fee_repaid);
    }

    /**
     * What the customer has paid us over and above the capital returned.
     *
     * The client calls this "faida" and puts it in Operating Income; it is the
     * interest and the charge fee together, never the principal.
     */
    public function profitRepaidMoney(): Money
    {
        return $this->interestRepaidMoney()->add($this->feeRepaidMoney());
    }

    /**
     * Days past the due date — the Alert column on the Active screen.
     *
     * Zero for anything not yet disbursed, already settled, or still in time. A
     * negative figure would be days remaining, which is a different question
     * and not the one this column asks.
     */
    public function overdueDays(?CarbonImmutable $asOf = null): int
    {
        if ($this->due_date === null || $this->status !== CustomerAdvanceStatus::Disbursed) {
            return 0;
        }

        $today = ($asOf ?? \Illuminate\Support\Facades\Date::now()->toImmutable())->startOfDay();

        return max(0, (int) $this->due_date->startOfDay()->diffInDays($today, false));
    }

    /**
     * @param Builder<CustomerAdvance> $query
     * @return Builder<CustomerAdvance>
     */
    public function scopeWithListRelations(Builder $query): Builder
    {
        return $query->with(self::LIST_RELATIONS);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CustomerAdvanceStatus::class,
            'amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'charge_fee' => 'decimal:2',
            'amount_repaid' => 'decimal:2',
            'principal_repaid' => 'decimal:2',
            'interest_repaid' => 'decimal:2',
            'fee_repaid' => 'decimal:2',
            'recovery_periods' => 'integer',
            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'disbursed_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'due_date' => 'immutable_date',
        ];
    }
}
