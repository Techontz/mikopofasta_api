<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Hr\Enums\StaffLoanStatus;
use App\Domain\Hr\Services\StaffLoanReferenceGenerator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.9 — `staff_loans`. §11's "internal mirror of the customer
 * loan engine", recovered automatically by payroll deduction.
 *
 * `amount_recovered` and `recovery_periods` were added in Module 7. Without
 * them nothing knew how much of a loan had been repaid, so nothing could tell
 * when it was finished — and payroll went on deducting a flat figure from
 * employees who had already cleared their debt.
 *
 * @property int $id
 * @property string $reference
 * @property int $staff_profile_id
 * @property string $amount
 * @property string $amount_recovered
 * @property int $recovery_periods
 * @property StaffLoanStatus $status
 * @property CarbonImmutable|null $requested_at
 * @property int|null $requested_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $disbursed_at
 * @property int|null $disbursed_by
 * @property CarbonImmutable|null $closed_at
 * @property int|null $journal_entry_id
 */
class StaffLoan extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'reference', 'staff_profile_id', 'amount', 'amount_recovered', 'recovery_periods',
        'status', 'requested_at', 'requested_by', 'approved_by', 'approved_at',
        'rejection_reason', 'disbursed_at', 'disbursed_by', 'closed_at', 'journal_entry_id',
    ];

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    /** What payroll has taken so far. */
    public function recoveredMoney(): Money
    {
        return Money::of($this->amount_recovered ?? '0.00');
    }

    /**
     * Assigns a reference to any loan created without one.
     *
     * The same hook `StaffAdvance` carries, for the same reason: `reference` is
     * NOT NULL and unique, the action supplies it, and a test fixture building
     * a loan directly would otherwise fail on a constraint that has nothing to
     * do with what it is testing.
     */
    protected static function booted(): void
    {
        static::creating(function (self $loan): void {
            if (($loan->reference ?? '') === '') {
                $loan->reference = app(StaffLoanReferenceGenerator::class)->next();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StaffLoanStatus::class,
            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'disbursed_at' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
            'recovery_periods' => 'integer',
        ];
    }
}
