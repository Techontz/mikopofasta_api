<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.5 — `loan_schedules`. One installment.
 *
 * Every amount is exposed as Money, never as a raw decimal string, so the
 * repayment engine (Phase 6) cannot accidentally do float arithmetic on a
 * balance.
 *
 * @property int $id
 * @property int $loan_id
 * @property int $installment_number
 * @property CarbonImmutable $due_date
 * @property string $principal_due
 * @property string $interest_due
 * @property string $penalty_due
 * @property string $principal_paid
 * @property string $interest_paid
 * @property string $penalty_paid
 * @property LoanScheduleStatus $status
 */
class LoanSchedule extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'loan_id', 'installment_number', 'due_date',
        'principal_due', 'interest_due', 'penalty_due',
        'principal_paid', 'interest_paid', 'penalty_paid', 'status',
    ];

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function principalDue(): Money
    {
        return Money::of($this->principal_due);
    }

    public function interestDue(): Money
    {
        return Money::of($this->interest_due);
    }

    public function penaltyDue(): Money
    {
        return Money::of($this->penalty_due);
    }

    public function totalDue(): Money
    {
        return $this->principalDue()->add($this->interestDue())->add($this->penaltyDue());
    }

    public function totalPaid(): Money
    {
        return Money::of($this->principal_paid)
            ->add(Money::of($this->interest_paid))
            ->add(Money::of($this->penalty_paid));
    }

    /**
     * Mirrors the frontend's `scheduleOutstanding().total`.
     */
    public function outstandingTotal(): Money
    {
        return $this->totalDue()->subtract($this->totalPaid());
    }

    public function outstandingPrincipal(): Money
    {
        return $this->principalDue()->subtract(Money::of($this->principal_paid));
    }

    public function outstandingInterest(): Money
    {
        return $this->interestDue()->subtract(Money::of($this->interest_paid));
    }

    public function outstandingPenalty(): Money
    {
        return $this->penaltyDue()->subtract(Money::of($this->penalty_paid));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'status' => LoanScheduleStatus::class,
            'installment_number' => 'integer',
        ];
    }
}
