<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's line on a payroll run: Base + Commission + Allowance − Deductions. `negligence` is recovered from the commission
 * only (spec §23).
 */
class PayrollItem extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return collect(['base_salary', 'commission', 'allowance', 'gross', 'staff_fund', 'salary_advance', 'deduction', 'negligence', 'loan_restoration', 'total_deductions', 'take_home'])
            ->mapWithKeys(fn (string $column): array => [$column => 'decimal:2'])
            ->all();
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function salaryPayment(): BelongsTo
    {
        return $this->belongsTo(SalaryPayment::class);
    }
}
