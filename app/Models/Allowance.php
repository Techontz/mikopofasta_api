<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Hr\Enums\AllowanceType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.9 — `allowances`.
 *
 * @property int $id
 * @property int $payroll_line_id
 * @property AllowanceType $type
 * @property string $amount
 */
class Allowance extends Model
{
    /** @var list<string> */
    protected $fillable = ['payroll_line_id', 'type', 'amount'];

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
        return ['type' => AllowanceType::class];
    }
}
