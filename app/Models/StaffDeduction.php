<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Hr\Enums\DeductionType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A deduction somebody decided — HRM → Staff → Deductions.
 *
 * The Staff Fund contribution, loan recovery and advance recovery are all
 * computed: each follows from a rate or a balance and payroll works it out.
 * A **penalty** cannot be, which is why this table exists — §11 of the HR
 * document lists penalties among the deduction types, `DeductionType::Penalty`
 * has always been in the enum and mapped to an account, and until now no code
 * path could create one.
 *
 * Always period-scoped, never recurring. A recurring penalty is a salary cut,
 * and it should be made as one — on the employee's profile, where it is visible
 * rather than buried in a deduction row.
 *
 * @property int $id
 * @property int $staff_profile_id
 * @property DeductionType $type
 * @property string $amount
 * @property string $period
 * @property string $reason
 * @property int $created_by
 */
class StaffDeduction extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['staff_profile_id', 'type', 'amount', 'period', 'reason', 'created_by'];

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

    /**
     * @param Builder<StaffDeduction> $query
     * @return Builder<StaffDeduction>
     */
    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->where('period', $period);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => DeductionType::class];
    }
}
