<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Target set by admin for the company, a zone, a branch or an officer over a period.
 */
class Goal extends Model
{
    use Auditable;

    /**
     * @var array<string, string>
     */
    public const METRICS = [
        'new_customers' => 'New customers',
        'loans_count' => 'Number of loans disbursed',
        'disbursement_amount' => 'Disbursement amount',
        'collections_amount' => 'Collections (repayments)',
    ];

    /** Metrics measured in money. */
    public const MONEY_METRICS = ['disbursement_amount', 'collections_amount'];

    /**
     * @var array<string, string>
     */
    public const SCOPES = [
        'company' => 'Company',
        'zone' => 'Zone',
        'branch' => 'Branch',
        'employee' => 'Officer',
    ];

    /**
     * @var array<string, string>
     */
    public const PERIODS = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'yearly' => 'Yearly',
        'custom' => 'Custom',
    ];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
