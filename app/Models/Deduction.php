<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Hr\Enums\DeductionType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.9 — `deductions`.
 *
 * `reference_id` points at `staff_loans.id` or `staff_advances.id` depending
 * on `type`, which is why §2.9 leaves it unconstrained.
 *
 * @property int $id
 * @property int $payroll_line_id
 * @property DeductionType $type
 * @property string $amount
 * @property int|null $reference_id
 */
class Deduction extends Model
{
    /** @var list<string> */
    protected $fillable = ['payroll_line_id', 'type', 'amount', 'reference_id'];

    /**
     * @return BelongsTo<PayrollLine, $this>
     */
    public function payrollLine(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class);
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => DeductionType::class];
    }
}
