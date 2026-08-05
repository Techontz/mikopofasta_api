<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * How a product charges a borrower for being late — administrator-managed.
 *
 * This was an ENUM on `loan_products`, which meant adding a penalty type
 * required a migration. It is master data now, so an administrator can define
 * one without a deploy.
 *
 * `rate_unit` and `accrues_daily` are stored rather than inferred from the
 * code, because PenaltyCalculator has to know how to read
 * `loan_products.penalty_rate` for a type it has never seen before. A future
 * "1.5% per day, capped at 30 days" needs its unit and its cadence stated as
 * data, not guessed from its name.
 *
 * Penalties remain completely independent of interest: nothing here feeds the
 * loan engine, and no penalty ever changes the interest that was agreed.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property string $rate_unit
 * @property bool $accrues_daily
 * @property bool $is_active
 */
class PenaltyType extends Model
{
    use SoftDeletes;

    /** `loan_products.penalty_rate` is a percentage of the overdue amount. */
    public const string UNIT_PERCENTAGE = 'percentage';

    /** `loan_products.penalty_rate` is a flat amount in shillings. */
    public const string UNIT_FIXED = 'fixed';

    /** @var list<string> */
    protected $fillable = ['name', 'code', 'description', 'rate_unit', 'accrues_daily', 'is_active'];

    /** @return HasMany<LoanProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(LoanProduct::class, 'penalty_type_id');
    }

    public function isPercentage(): bool
    {
        return $this->rate_unit === self::UNIT_PERCENTAGE;
    }

    public function isFixed(): bool
    {
        return $this->rate_unit === self::UNIT_FIXED;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'accrues_daily' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
