<?php

namespace App\Models;

use App\Services\LoanRecoveryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Money recovered on a written-off loan (C3 Option B, {@see LoanRecoveryService}): a new transaction split Principal → Penalty →
 * Interest → Insurance within what was written off, linked to its payment and the original write-off. Kept apart from
 * loan_transactions so the repayment outstanding and freeze rules never see it.
 *
 * Legacy rows (posted under the old rule, every component column NULL) are interest-only recoveries (Dr INTEREST A/C /
 * Cr INTEREST INCOME for the full amount) and stay as booked.
 */
class LoanRecovery extends Model
{
    /** @var list<string> */
    public const COMPONENTS = ['principal', 'penalty', 'interest', 'insurance'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'principal_amount' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'reserve_amount' => 'decimal:2',
            'insurance_amount' => 'decimal:2',
            'recovered_on' => 'date',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * Posted under the old interest-only rule (no component split stored).
     */
    public function isLegacy(): bool
    {
        return $this->principal_amount === null && $this->penalty_amount === null && $this->interest_amount === null && $this->insurance_amount === null;
    }

    /**
     * The recovered amount per component; a legacy row is interest only.
     *
     * @return array{principal: float, penalty: float, interest: float, insurance: float, reserve: float}
     */
    public function componentAmounts(): array
    {
        if ($this->isLegacy()) {
            return ['principal' => 0.0, 'penalty' => 0.0, 'interest' => (float) $this->amount, 'insurance' => 0.0, 'reserve' => 0.0];
        }

        return [
            'principal' => (float) $this->principal_amount,
            'penalty' => (float) $this->penalty_amount,
            'interest' => (float) $this->interest_amount,
            'insurance' => (float) $this->insurance_amount,
            'reserve' => (float) $this->reserve_amount,
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function writeOff(): BelongsTo
    {
        return $this->belongsTo(WriteOff::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reversed_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function paymentAllocation(): HasOne
    {
        return $this->hasOne(PaymentAllocation::class);
    }

    /**
     * @param  Builder<LoanRecovery>  $query
     */
    public function scopeStanding(Builder $query): void
    {
        $query->whereNull('reversed_at');
    }
}
