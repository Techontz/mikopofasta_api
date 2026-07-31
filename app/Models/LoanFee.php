<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Loans\Enums\ChargeValueType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The arrangement fee and insurance premium charged on a loan product —
 * Settings → Loan Fee.
 *
 * Configuration only. Nothing charges it yet; see docs/modules/loan-charges.md
 * for what wiring it into disbursement would involve.
 *
 * @property int $id
 * @property int $loan_product_id
 * @property ChargeValueType $fee_type
 * @property string $fee_amount
 * @property string $insurance_amount
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class LoanFee extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['loan_product_id', 'fee_type', 'fee_amount', 'insurance_amount', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fee_type' => ChargeValueType::class,
            'fee_amount' => 'decimal:2',
            'insurance_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<LoanProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
