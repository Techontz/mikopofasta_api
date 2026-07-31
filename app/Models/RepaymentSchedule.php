<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Backend spec §2.3 — `repayment_schedules`. The cadence entity, deliberately
 * separate from LoanProduct.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $frequency_days
 */
class RepaymentSchedule extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['name', 'code', 'frequency_days'];

    /**
     * @return BelongsToMany<LoanProduct, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(LoanProduct::class, 'loan_product_repayment_schedules');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['frequency_days' => 'integer'];
    }
}
