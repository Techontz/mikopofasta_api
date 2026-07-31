<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Loans\Enums\ChargeValueType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The organisation-wide penalty default — Settings → Penalty.
 *
 * NOT the value any penalty is calculated from. Live loans carry their own
 * `penalty_rate_snapshot` and loan products their own penalty columns; this is
 * the default a newly created product starts from. See the boundary note in
 * docs/modules/loan-charges.md.
 *
 * @property int $id
 * @property ChargeValueType $calculation_type
 * @property string $amount
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class PenaltySetting extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['calculation_type', 'amount', 'created_by'];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'calculation_type' => ChargeValueType::class,
            'amount' => 'decimal:3',
        ];
    }
}
