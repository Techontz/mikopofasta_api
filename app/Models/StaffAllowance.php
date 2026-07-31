<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Hr\Enums\AllowanceType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What an employee is entitled to draw — HRM → Staff → Allowances.
 *
 * Distinct from `allowances`, which is what a payslip actually paid. Payroll
 * copies this into that, and keeping them apart is what lets a transport rate
 * change next month without rewriting last month's payslip.
 *
 * A row with no `period` is recurring: drawn every month until stood down. A
 * row with one applies to that month alone, which is what a bonus is — §10 of
 * the HR document lists it beside transport and airtime, but a bonus that
 * repeated silently every month would be a salary increase nobody approved.
 *
 * @property int $id
 * @property int $staff_profile_id
 * @property AllowanceType $type
 * @property string $amount
 * @property string|null $period
 * @property string|null $reason
 * @property bool $active
 * @property int $created_by
 */
class StaffAllowance extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'staff_profile_id', 'type', 'amount', 'period', 'reason', 'active', 'created_by',
    ];

    /** @return BelongsTo<StaffProfile, $this> */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    /** Recurring — drawn every month rather than in one named month. */
    public function isRecurring(): bool
    {
        return $this->period === null;
    }

    /**
     * The entitlements that apply to a given month.
     *
     * Every live recurring row, plus the one-offs stamped with this period.
     * Inactive rows are excluded here rather than by the caller, so a
     * stood-down allowance cannot be picked up by a code path that forgot to
     * check.
     *
     * @param Builder<StaffAllowance> $query
     * @return Builder<StaffAllowance>
     */
    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('period')->orWhere('period', $period));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AllowanceType::class,
            'active' => 'boolean',
        ];
    }
}
