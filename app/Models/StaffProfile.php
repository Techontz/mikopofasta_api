<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Hr\Enums\EmploymentStatus;
use App\Domain\Hr\Enums\StaffAdvanceStatus;
use App\Domain\Hr\Enums\StaffLoanStatus;
use App\Domain\Hr\Enums\StaffPaymentMethod;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Backend spec §2.9 — `staff_profiles`.
 *
 * §11: a staff member needs no ledger tables of their own. `staff_profile_id`
 * is a dimension on `journal_entry_lines`, so Staff Control, Staff Loan, Staff
 * Advance and Staff Deductions are all filtered views of the one ledger.
 *
 * @property int $id
 * @property int $user_id
 * @property string $employee_number
 * @property int|null $branch_id
 * @property int|null $zone_id
 * @property string $base_salary
 * @property bool $commission_eligible
 * @property StaffPaymentMethod $payment_method
 * @property EmploymentStatus $employment_status
 * @property CarbonImmutable $hired_at
 * @property CarbonImmutable|null $deleted_at
 */
class StaffProfile extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'employee_number', 'branch_id', 'zone_id', 'base_salary',
        'commission_eligible', 'payment_method', 'employment_status', 'hired_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * @return HasOne<StaffBankDetail, $this>
     */
    public function bankDetail(): HasOne
    {
        return $this->hasOne(StaffBankDetail::class);
    }

    /**
     * @return HasMany<PayrollLine, $this>
     */
    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /**
     * @return HasMany<StaffLoan, $this>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(StaffLoan::class);
    }

    /**
     * @return HasMany<StaffAdvance, $this>
     */
    public function advances(): HasMany
    {
        return $this->hasMany(StaffAdvance::class);
    }

    /**
     * @return HasMany<CommissionDistribution, $this>
     */
    public function commissionShares(): HasMany
    {
        return $this->hasMany(CommissionDistribution::class);
    }

    /**
     * @return HasMany<StaffPerformanceRecord, $this>
     */
    public function performanceRecords(): HasMany
    {
        return $this->hasMany(StaffPerformanceRecord::class);
    }

    public function baseSalary(): Money
    {
        return Money::of($this->base_salary);
    }

    /**
     * How this employee is named on a payslip, a journal entry description and
     * every screen that lists them.
     *
     * `user_id` is NOT NULL behind a RESTRICT foreign key, so there is always
     * a user; the employee number is a fallback only for the case where the
     * relation has not been loaded and the caller wants no extra query.
     */
    public function displayName(): string
    {
        return $this->relationLoaded('user') && $this->user === null
            ? $this->employee_number
            : $this->user->name;
    }

    /**
     * Whether an outstanding staff loan is being recovered from this salary.
     *
     * Reads the loaded relation when there is one so a payroll run over every
     * employee does not fire two queries per person.
     */
    public function hasActiveLoan(): bool
    {
        if ($this->relationLoaded('loans')) {
            return $this->loans->contains(fn (StaffLoan $l): bool => $l->status === StaffLoanStatus::Active);
        }

        return $this->loans()->where('status', StaffLoanStatus::Active)->exists();
    }

    public function hasOutstandingAdvance(): bool
    {
        if ($this->relationLoaded('advances')) {
            return $this->advances->contains(fn (StaffAdvance $a): bool => $a->status->isOutstanding());
        }

        return $this->advances()->where('status', StaffAdvanceStatus::Disbursed)->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_method' => StaffPaymentMethod::class,
            'employment_status' => EmploymentStatus::class,
            'commission_eligible' => 'boolean',
            'hired_at' => 'immutable_date',
        ];
    }
}
