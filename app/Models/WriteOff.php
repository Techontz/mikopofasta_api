<?php

namespace App\Models;

use App\Services\LoanRecoveryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A posted write-off. principal_amount / interest_amount / penalty_amount / insurance_amount snapshot the loan's outstanding
 * components at write-off (NULL on write-offs posted before the snapshot existed) and cap the recoveries per component
 * ({@see LoanRecoveryService::components()}).
 */
class WriteOff extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'written_off_on' => 'date',
            'amount' => 'decimal:2',
            'principal_amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'insurance_amount' => 'decimal:2',
            'recovered_amount' => 'decimal:2',
        ];
    }

    /**
     * Recovered so far: the legacy recovered_amount column (never written by this system) plus every recovery that still
     * stands ({@see LoanRecovery}), as a SQL expression usable in selects and filters.
     */
    public static function recoveredSql(string $table = 'write_offs'): string
    {
        return "({$table}.recovered_amount + COALESCE((SELECT SUM(loan_recoveries.amount) FROM loan_recoveries WHERE loan_recoveries.write_off_id = {$table}.id AND loan_recoveries.reversed_at IS NULL), 0))";
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(LoanRecovery::class);
    }

    public function recoveredTotal(): float
    {
        return round((float) $this->recovered_amount + (float) $this->recoveries()->whereNull('reversed_at')->sum('amount'), 2);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
