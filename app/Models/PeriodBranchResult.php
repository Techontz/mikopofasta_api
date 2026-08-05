<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one branch contributed to one closed period.
 *
 * The reserve is a single company-wide account (§5's 3000) but it is
 * appropriated with a branch dimension, because the profit it comes out of was
 * earned by a branch and §11's commission pool is computed from exactly these
 * figures. Without this row, "why is this branch's pool what it is" would have
 * no answer once the period was closed and its income accounts swept.
 *
 * @property int $id
 * @property int $accounting_period_id
 * @property int $branch_id
 * @property string $income_total
 * @property string $expense_total
 * @property string $realised_profit
 * @property string $reserve_appropriated
 */
class PeriodBranchResult extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'accounting_period_id', 'branch_id',
        'income_total', 'expense_total', 'realised_profit', 'reserve_appropriated',
    ];

    /** @return BelongsTo<AccountingPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function realisedProfitMoney(): Money
    {
        return Money::of($this->realised_profit);
    }

    public function reserveAppropriatedMoney(): Money
    {
        return Money::of($this->reserve_appropriated);
    }

    /**
     * The reserve appropriated from one branch in one period, or zero.
     *
     * The commission engine's entry point: §11 needs the deduction that was
     * actually made, not one it recomputes, so that a pool always reconciles to
     * the close it was derived from even if the reserve rate changes later.
     */
    public static function reserveFor(int $branchId, string $period): Money
    {
        $value = static::query()
            ->where('branch_id', $branchId)
            ->whereHas('period', fn ($q) => $q->where('period', $period))
            ->value('reserve_appropriated');

        return $value === null ? Money::zero() : Money::of((string) $value);
    }
}
