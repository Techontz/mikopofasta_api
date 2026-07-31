<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Hr\Enums\PayrollRunStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Backend spec §2.9 — `payroll_runs`.
 *
 * §14's separation of duties is the shape of this table: `generated_by` is HR,
 * `finalized_at` is Finance's, and the two can never be the same act.
 *
 * @property int $id
 * @property string $period
 * @property PayrollRunStatus $status
 * @property int $generated_by
 * @property CarbonImmutable|null $finalized_at
 */
class PayrollRun extends Model
{
    /** @var list<string> */
    protected $fillable = ['period', 'status', 'generated_by', 'finalized_at'];

    /**
     * @return HasMany<PayrollLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** The total net pay this run will move, across every line. */
    public function netTotal(): Money
    {
        return Money::sum($this->lines->map(fn (PayrollLine $l): Money => $l->netSalary()));
    }

    public function isDraft(): bool
    {
        return $this->status === PayrollRunStatus::Draft;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayrollRunStatus::class,
            'finalized_at' => 'immutable_datetime',
        ];
    }
}
