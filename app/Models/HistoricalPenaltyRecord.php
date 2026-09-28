<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a historical Penalty report, as printed. A negative penalty_amount is a waiver or correction the old
 * system printed as a negative figure; a null customer_name is a row the printout left blank. Neither is "fixed".
 */
class HistoricalPenaltyRecord extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'serial_number' => 'integer',
            'loan_amount' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'penalty_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<HistoricalPenaltyReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(HistoricalPenaltyReport::class, 'historical_penalty_report_id');
    }

    /**
     * The customer matched to this row (see mkopa:create-historical-customers), when the name matched one.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
