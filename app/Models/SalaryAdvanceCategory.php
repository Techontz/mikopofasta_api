<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use App\Support\Percentage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A salary advance band — Salary Advance → Salary Advance Category.
 *
 * Decides what an advance of a given size costs and how long it runs. A request
 * does not choose its category; the amount does, by falling inside a band. That
 * is what stops two employees borrowing the same amount on different terms.
 *
 * @property int $id
 * @property string $name
 * @property string $interest_rate
 * @property string $from_amount
 * @property string $to_amount
 * @property string $charge_fee
 * @property int $recovery_periods
 */
class SalaryAdvanceCategory extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name', 'interest_rate', 'from_amount', 'to_amount',
        'charge_fee', 'recovery_periods', 'created_by',
    ];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<StaffAdvance, $this> */
    public function advances(): HasMany
    {
        return $this->hasMany(StaffAdvance::class);
    }

    public function interestRate(): Percentage
    {
        return Percentage::of($this->interest_rate);
    }

    public function fromAmount(): Money
    {
        return Money::of($this->from_amount);
    }

    public function toAmount(): Money
    {
        return Money::of($this->to_amount);
    }

    public function chargeFee(): Money
    {
        return Money::of($this->charge_fee);
    }

    /** Whether an advance of this size falls in this band. Bounds inclusive. */
    public function covers(Money $amount): bool
    {
        return ! $amount->lessThan($this->fromAmount())
            && ! $amount->greaterThan($this->toAmount());
    }

    /**
     * The band covering an amount, or null.
     *
     * Narrowest first, so overlapping bands resolve to the more specific one
     * rather than to whichever happens to have the lower id. Overlaps are
     * refused at creation, so this is a tie-break that should never be needed —
     * but a silent wrong answer here would misprice a real advance.
     *
     * @param Builder<SalaryAdvanceCategory>|null $query
     */
    public static function covering(Money $amount, ?Builder $query = null): ?self
    {
        return ($query ?? self::query())
            ->where('from_amount', '<=', $amount->toDecimalString())
            ->where('to_amount', '>=', $amount->toDecimalString())
            ->orderByRaw('(to_amount - from_amount) ASC')
            ->orderBy('id')
            ->first();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'interest_rate' => 'decimal:3',
            'from_amount' => 'decimal:2',
            'to_amount' => 'decimal:2',
            'charge_fee' => 'decimal:2',
            'recovery_periods' => 'integer',
        ];
    }
}
