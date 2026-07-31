<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Hr\Enums\StaffAdvanceStatus;
use App\Domain\Hr\Services\StaffAdvanceReferenceGenerator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Backend spec §2.9 — `staff_advances`.
 *
 * §11's lifecycle, and the separation it insists on: HR approves, **Finance**
 * disburses, never HR.
 *
 * The terms — interest, charge fee and recovery periods — are snapshotted from
 * the category at request, so re-pricing a band never rewrites an advance
 * already agreed. `amount_recovered` accumulates as payroll deducts, and the
 * advance closes when it clears; see SalaryAdvanceCalculator.
 *
 * @property int $id
 * @property string $reference
 * @property int $staff_profile_id
 * @property int|null $salary_advance_category_id
 * @property string $amount
 * @property string $interest_amount
 * @property string $charge_fee
 * @property int $recovery_periods
 * @property string $amount_recovered
 * @property StaffAdvanceStatus $status
 * @property CarbonImmutable $requested_at
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $disbursed_at
 * @property CarbonImmutable|null $recovered_at
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $due_date
 * @property int|null $journal_entry_id
 */
class StaffAdvance extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    public const LIST_RELATIONS = ['staffProfile.user', 'staffProfile.branch', 'category', 'approver'];

    /** @var list<string> */
    protected $fillable = [
        'reference', 'staff_profile_id', 'salary_advance_category_id',
        'amount', 'interest_amount', 'charge_fee', 'recovery_periods', 'amount_recovered',
        'status', 'requested_at', 'approved_by', 'approved_at', 'disbursed_at',
        'recovered_at', 'rejection_reason', 'due_date', 'journal_entry_id',
    ];

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<SalaryAdvanceCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SalaryAdvanceCategory::class, 'salary_advance_category_id')->withTrashed();
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    public function interestMoney(): Money
    {
        return Money::of($this->interest_amount);
    }

    public function chargeFeeMoney(): Money
    {
        return Money::of($this->charge_fee);
    }

    public function recoveredMoney(): Money
    {
        return Money::of($this->amount_recovered);
    }

    /**
     * Days past the recovery due date — the Alert column on the Active screen.
     *
     * Zero for anything not yet disbursed, already cleared, or still in time.
     * A negative figure would be days remaining, which is a different question
     * and not one this column asks.
     */
    public function overdueDays(?CarbonImmutable $asOf = null): int
    {
        if ($this->due_date === null || $this->status !== StaffAdvanceStatus::Disbursed) {
            return 0;
        }

        $today = ($asOf ?? \Illuminate\Support\Facades\Date::now()->toImmutable())->startOfDay();

        return max(0, (int) $this->due_date->startOfDay()->diffInDays($today, false));
    }

    /**
     * @param Builder<StaffAdvance> $query
     * @return Builder<StaffAdvance>
     */
    public function scopeWithListRelations(Builder $query): Builder
    {
        return $query->with(self::LIST_RELATIONS);
    }

    /**
     * Assigns a reference to any advance created without one.
     *
     * `reference` is NOT NULL and unique, and StaffAdvanceAction supplies it —
     * but a seeder, a factory or a test constructing an advance directly would
     * otherwise fail at the database with an opaque message about a missing
     * default. Filling it here means every construction path yields a valid
     * record, and a caller that already set one keeps it.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $advance): void {
            if (($advance->reference ?? '') === '') {
                $advance->reference = app(StaffAdvanceReferenceGenerator::class)->next();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StaffAdvanceStatus::class,
            'amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'charge_fee' => 'decimal:2',
            'amount_recovered' => 'decimal:2',
            'recovery_periods' => 'integer',
            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'disbursed_at' => 'immutable_datetime',
            'recovered_at' => 'immutable_datetime',
            'due_date' => 'immutable_date',
        ];
    }
}
