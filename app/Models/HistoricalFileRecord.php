<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of a historical File report, as printed. status is the printed text ("Done", "Active", "Default", "New" or blank),
 * not a LoanStatus: the row is not a loan of this system.
 */
class HistoricalFileRecord extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'serial_number' => 'integer',
            'sessions' => 'integer',
            'loan_amount' => 'decimal:2',
            'collection' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'remain_amount' => 'decimal:2',
            'withdrawal_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<HistoricalFileReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(HistoricalFileReport::class, 'historical_file_report_id');
    }

    /**
     * The customer created from this row (see mkopa:create-historical-customers).
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<HistoricalFilePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(HistoricalFilePayment::class);
    }

    /**
     * The File report's badge tone for the printed status.
     */
    public function statusBadge(): string
    {
        return match (strtoupper((string) $this->status)) {
            'DONE' => 'success',
            'ACTIVE' => 'primary',
            'DEFAULT' => 'danger',
            'NEW' => 'warning',
            default => 'default',
        };
    }
}
