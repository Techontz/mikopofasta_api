<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Month-end profit of one branch (OVERVIEW ALL REPORT "Branch Profit & Loss", STAFF COMMISSION
 * "distributable profit"). Read by the commission engine and financial reports.
 */
class BranchPeriodResult extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interest_income' => 'decimal:2',
            'reserve_amount' => 'decimal:2',
            'fee_income' => 'decimal:2',
            'penalty_income' => 'decimal:2',
            'recovery_income' => 'decimal:2',
            'salary_advance_income' => 'decimal:2',
            'total_income' => 'decimal:2',
            'expenses' => 'decimal:2',
            'gross_profit' => 'decimal:2',
            'loss_brought_forward' => 'decimal:2',
            'net_profit' => 'decimal:2',
            'loss_carried_forward' => 'decimal:2',
            'hq_hold_percent' => 'decimal:2',
            'hq_hold_amount' => 'decimal:2',
            'distributable_profit' => 'decimal:2',
            'commission_eligible' => 'boolean',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
