<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Monthly payroll (STAFF COMMISSION §4): generated and approved by HR, paid by Finance. `expense_date` is the date its salary,
 * allowance and company contribution expense was recognised on (spec §20: inside the payroll period); `expense_period_note`
 * explains when the period was already closed and that date could not be used.
 */
class PayrollRun extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => 'date',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'expense_date' => 'date',
            'total_gross' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'paid_by');
    }
}
