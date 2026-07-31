<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Backend spec §2.9 — `payroll_lines`. One employee's pay for one period.
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property int $staff_profile_id
 * @property string $base_salary
 * @property string $commission_amount
 * @property string $allowances_total
 * @property string $deductions_total
 * @property string $net_salary
 * @property int|null $journal_entry_id
 */
class PayrollLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'payroll_run_id', 'staff_profile_id', 'base_salary', 'commission_amount',
        'allowances_total', 'deductions_total', 'net_salary', 'journal_entry_id',
    ];

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    /**
     * @return HasMany<Allowance, $this>
     */
    public function allowances(): HasMany
    {
        return $this->hasMany(Allowance::class);
    }

    /**
     * @return HasMany<Deduction, $this>
     */
    public function deductions(): HasMany
    {
        return $this->hasMany(Deduction::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function baseSalary(): Money
    {
        return Money::of($this->base_salary);
    }

    public function commissionAmount(): Money
    {
        return Money::of($this->commission_amount);
    }

    public function allowancesTotal(): Money
    {
        return Money::of($this->allowances_total);
    }

    public function deductionsTotal(): Money
    {
        return Money::of($this->deductions_total);
    }

    public function netSalary(): Money
    {
        return Money::of($this->net_salary);
    }

    /**
     * What the employer recognises as owed before deductions — the credit side
     * of the recognition entry.
     */
    public function grossPay(): Money
    {
        return $this->baseSalary()->add($this->allowancesTotal())->add($this->commissionAmount());
    }
}
