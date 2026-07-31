<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use App\Support\Percentage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Backend spec §2.9 — `commission_pools`.
 *
 * Commission is branch-performance-based, never individual-sales-based (§11).
 * The pool is what a branch earned the right to share out; who gets what is
 * `commission_distributions`.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $period
 * @property string $branch_profit
 * @property string $loss_carry_forward
 * @property string $hq_hold_amount
 * @property string $distributable_profit
 * @property string $pool_percentage
 * @property string $pool_amount
 */
class CommissionPool extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'branch_id', 'period', 'branch_profit', 'loss_carry_forward', 'hq_hold_amount',
        'distributable_profit', 'pool_percentage', 'pool_amount',
    ];

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<CommissionDistribution, $this>
     */
    public function distributions(): HasMany
    {
        return $this->hasMany(CommissionDistribution::class);
    }

    public function branchProfit(): Money
    {
        return Money::of($this->branch_profit);
    }

    public function lossCarryForward(): Money
    {
        return Money::of($this->loss_carry_forward);
    }

    public function distributableProfit(): Money
    {
        return Money::of($this->distributable_profit);
    }

    public function poolAmount(): Money
    {
        return Money::of($this->pool_amount);
    }

    public function poolPercentage(): Percentage
    {
        return Percentage::of($this->pool_percentage);
    }

    /**
     * §11's hard rule: "commission_distributions for a branch/period cannot be
     * created while commission_pools.distributable_profit <= 0 (loss must be
     * offset first)."
     */
    public function isDistributable(): bool
    {
        return $this->distributableProfit()->isPositive();
    }

    /** The unrecovered loss this pool leaves behind for the next period. */
    public function shortfall(): Money
    {
        return $this->isDistributable()
            ? Money::zero()
            : Money::zero()->subtract($this->distributableProfit());
    }
}
