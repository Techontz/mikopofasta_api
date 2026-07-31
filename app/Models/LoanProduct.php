<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Loans\Enums\PenaltyType;
use App\Enums\ActiveStatus;
use App\Support\Money;
use App\Support\Percentage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Backend spec §2.3 — `loan_products`.
 *
 * Every commercial term of a loan lives here (§6): tenure, amount, interest
 * rate and formula, valid cadences, mandate requirement, and the whole penalty
 * configuration. There is no fallback constant anywhere in application code —
 * the loan engine reads this row for every decision it makes.
 *
 * The money and rate accessors return value objects rather than the raw
 * decimal strings, so no caller is ever handed a float.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $interest_formula_id
 * @property string $interest_rate
 * @property string $min_amount
 * @property string $max_amount
 * @property int $min_tenure_days
 * @property int $max_tenure_days
 * @property PenaltyType $penalty_type
 * @property string $penalty_rate
 * @property int $penalty_grace_days
 * @property string|null $penalty_cap_amount
 * @property bool $requires_mandate
 * @property ActiveStatus $status
 * @property int|null $created_by
 * @property CarbonImmutable|null $deleted_at
 */
class LoanProduct extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name', 'code', 'interest_formula_id', 'interest_rate',
        'min_amount', 'max_amount', 'min_tenure_days', 'max_tenure_days',
        'penalty_type', 'penalty_rate', 'penalty_grace_days', 'penalty_cap_amount',
        'requires_mandate', 'status', 'created_by',
    ];

    /**
     * @return BelongsTo<InterestFormula, $this>
     */
    public function interestFormula(): BelongsTo
    {
        return $this->belongsTo(InterestFormula::class);
    }

    /**
     * The cadences this product allows (§2.3 pivot).
     *
     * @return BelongsToMany<RepaymentSchedule, $this>
     */
    public function repaymentSchedules(): BelongsToMany
    {
        return $this->belongsToMany(RepaymentSchedule::class, 'loan_product_repayment_schedules');
    }

    /**
     * @return HasMany<CategoryProductEligibility, $this>
     */
    public function eligibilityRules(): HasMany
    {
        return $this->hasMany(CategoryProductEligibility::class, 'loan_product_id');
    }

    /**
     * @return HasMany<Loan, $this>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether a loan under this product may use the given cadence (§6).
     */
    public function allowsSchedule(int $repaymentScheduleId): bool
    {
        return $this->repaymentSchedules
            ->contains(fn (RepaymentSchedule $s): bool => $s->getKey() === $repaymentScheduleId);
    }

    public function minAmountMoney(): Money
    {
        return Money::of($this->min_amount);
    }

    public function maxAmountMoney(): Money
    {
        return Money::of($this->max_amount);
    }

    public function interestRate(): Percentage
    {
        return Percentage::of($this->interest_rate);
    }

    /**
     * `penalty_rate` read as a RATE — meaningful for the two percentage
     * penalty types (§2.3).
     */
    public function penaltyRate(): Percentage
    {
        return Percentage::of($this->penalty_rate);
    }

    /**
     * `penalty_rate` read as an AMOUNT — meaningful only for `flat_fee`,
     * where §2.3 says the column holds a flat amount rather than a rate.
     * See OSC-2 on the migration for why the column is DECIMAL(18,3).
     */
    public function penaltyFlatAmount(): Money
    {
        return Money::of($this->penalty_rate);
    }

    public function penaltyCapAmount(): ?Money
    {
        return $this->penalty_cap_amount === null ? null : Money::of($this->penalty_cap_amount);
    }

    public function isActive(): bool
    {
        return $this->status === ActiveStatus::Active;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'penalty_type' => PenaltyType::class,
            'status' => ActiveStatus::class,
            'requires_mandate' => 'boolean',
            'min_tenure_days' => 'integer',
            'max_tenure_days' => 'integer',
            'penalty_grace_days' => 'integer',
        ];
    }

    /**
     * The configured arrangement fee and insurance premium, if any.
     * Settings → Loan Fee; see docs/modules/loan-charges.md.
     *
     * @return HasOne<LoanFee, $this>
     */
    public function fee(): HasOne
    {
        return $this->hasOne(LoanFee::class);
    }
}
