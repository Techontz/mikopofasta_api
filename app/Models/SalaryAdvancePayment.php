<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A deposit on a customer salary advance. It is never deleted: a reversal (Reversal Requests) mirrors its journal and sets
 * `reversed_at`, and a reversed deposit counts nowhere (see {@see SalaryAdvance::payments()}).
 */
class SalaryAdvancePayment extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    public function salaryAdvance(): BelongsTo
    {
        return $this->belongsTo(SalaryAdvance::class);
    }

    /**
     * The deposit's own posting (its reversal shares the source and is left out).
     */
    public function journalEntry(): MorphOne
    {
        return $this->morphOne(JournalEntry::class, 'source')->whereNull('reversal_of_id')->oldestOfMany();
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reversed_by');
    }

    public function reversalRequests(): MorphMany
    {
        return $this->morphMany(ReversalRequest::class, 'subject');
    }
}
