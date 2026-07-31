<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.6 — `payment_allocations`. One row per installment a
 * payment touched, in Penalty → Interest → Principal order (§7).
 *
 * @property int $id
 * @property int $payment_id
 * @property int $loan_schedule_id
 * @property string $penalty_allocated
 * @property string $interest_allocated
 * @property string $principal_allocated
 * @property CarbonImmutable $created_at
 */
class PaymentAllocation extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'payment_id', 'loan_schedule_id',
        'penalty_allocated', 'interest_allocated', 'principal_allocated', 'created_at',
    ];

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<LoanSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(LoanSchedule::class, 'loan_schedule_id');
    }

    public function total(): Money
    {
        return Money::of($this->penalty_allocated)
            ->add(Money::of($this->interest_allocated))
            ->add(Money::of($this->principal_allocated));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
