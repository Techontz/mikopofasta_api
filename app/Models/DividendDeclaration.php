<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * A monthly profit distribution: reinvested share (to Principal) and shareholder dividend pool, with the settings
 * percentages, the server-computed profit and the share-register snapshot (total shares, as-of date) used at declaration.
 */
class DividendDeclaration extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => 'date',
            'profit_amount' => 'decimal:2',
            'reinvest_percent' => 'decimal:2',
            'reinvest_amount' => 'decimal:2',
            'dividend_percent' => 'decimal:2',
            'dividend_amount' => 'decimal:2',
            'distributable_profit' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'total_shares' => 'integer',
            'as_of_date' => 'date',
            'declared_at' => 'datetime',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(DividendAllocation::class);
    }

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(DividendPayment::class, DividendAllocation::class);
    }

    /**
     * "August 2026".
     */
    public function periodLabel(): string
    {
        return $this->period->format('F Y');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reinvestmentJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reinvestment_journal_entry_id');
    }

    /**
     * Declared under the profit-allocation rule (commission first, reinvestment to REINVESTED PROFIT and branch principal).
     */
    public function isProfitAllocationRule(): bool
    {
        return $this->allocation_rule !== null;
    }

    public function declaredBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'declared_by');
    }
}
