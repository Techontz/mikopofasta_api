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
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $finalized_at
 * @property int|null $finalized_by
 * @property int|null $paid_by
 * @property CarbonImmutable|null $paid_at
 */
class PayrollRun extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'period', 'status', 'generated_by', 'approved_by', 'approved_at',
        'finalized_at', 'finalized_by', 'paid_by', 'paid_at',
    ];

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

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /** @return BelongsTo<User, $this> */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function isDraft(): bool
    {
        return $this->status === PayrollRunStatus::Draft;
    }

    /**
     * Whether the figures may still change — §16.1's rule, asked of the run.
     *
     * The enum holds the answer so that every caller gets the same one; this is
     * only the convenience of asking the run rather than its status.
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayrollRunStatus::class,
            'approved_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }
}
