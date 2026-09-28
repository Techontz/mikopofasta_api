<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Penalty extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'penalty_date' => 'date',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'is_waived' => 'boolean',
            'is_legacy_opening' => 'boolean',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function accrualJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'accrual_journal_entry_id');
    }

    public function waiverJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'waiver_journal_entry_id');
    }

    /**
     * Accrued when charged (income recognised with a PENALTY RECEIVABLE); legacy penalties are cash basis.
     */
    public function isAccrued(): bool
    {
        return $this->accrual_journal_entry_id !== null;
    }

    /**
     * @return BelongsTo<LegacyImportRow, $this>
     */
    public function legacyImportRow(): BelongsTo
    {
        return $this->belongsTo(LegacyImportRow::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PenaltyPayment::class);
    }
}
