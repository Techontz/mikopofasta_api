<?php

namespace App\Models;

use App\Enums\Account;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Customer salary advance. The category fee is income only when collected (C2): {@see self::feeStatus()}.
 */
class SalaryAdvance extends Model
{
    public const FEE_NONE = 'no_fee';

    public const FEE_NOT_APPROVED = 'not_approved';

    public const FEE_UNCOLLECTED = 'uncollected';

    public const FEE_COLLECTED = 'collected';

    /** Carried over from the old system (legacy import): the "Charges" were that system's business, never collected here. */
    public const FEE_OLD_SYSTEM = 'old_system';

    /** Legacy: the fee journal was posted at approval (before C2); it stays as booked and counts as collected. */
    public const FEE_COLLECTED_AT_APPROVAL = 'collected_at_approval';

    use Auditable;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'total_payable' => 'decimal:2',
            'approved_at' => 'datetime',
            'reversed_at' => 'datetime',
            'fee_collected_at' => 'datetime',
            'is_legacy_opening' => 'boolean',
            'opening_paid' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SalaryAdvanceCategory::class, 'salary_advance_category_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function feeCollector(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'fee_collected_by');
    }

    public function feeJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'fee_journal_entry_id');
    }

    /**
     * LEGACY detection: an approval journal of this advance (source = the advance, no fee_journal_entry_id link) that credits
     * FEE INCOME — approvals before C2 posted Dr LOAN FEE A/C / Cr FEE INCOME together with the advance itself.
     */
    public function feePostedAtApproval(): bool
    {
        return JournalEntry::query()
            ->where('source_type', $this->getMorphClass())
            ->where('source_id', $this->id)
            ->whereNull('reversal_of_id')
            ->when($this->fee_journal_entry_id !== null, fn ($query) => $query->whereKeyNot($this->fee_journal_entry_id))
            ->whereHas('lines', fn ($lines) => $lines->where('credit', '>', 0)->whereHas('account', fn ($account) => $account->where('key', Account::FeeIncome->value)))
            ->exists();
    }

    /**
     * Fee collection status: no fee, not approved yet (pending), uncollected, collected (with its journal) or collected at
     * approval (legacy). A reversed advance keeps the status it had (its fee journal, if any, is reversed with it).
     */
    public function feeStatus(): string
    {
        return match (true) {
            (float) $this->fee <= 0 => self::FEE_NONE,
            $this->is_legacy_opening => self::FEE_OLD_SYSTEM,
            $this->fee_journal_entry_id !== null => self::FEE_COLLECTED,
            $this->approved_at !== null && $this->feePostedAtApproval() => self::FEE_COLLECTED_AT_APPROVAL,
            $this->status === 'pending' || $this->approved_at === null => self::FEE_NOT_APPROVED,
            default => self::FEE_UNCOLLECTED,
        };
    }

    /**
     * @return BelongsTo<LegacyImportRow, $this>
     */
    public function legacyImportRow(): BelongsTo
    {
        return $this->belongsTo(LegacyImportRow::class);
    }

    /**
     * The deposits that count: reversed ones are left out, so every sum of what was paid excludes them.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SalaryAdvancePayment::class)->whereNull('reversed_at');
    }

    /**
     * Every deposit, reversed ones included (deposit history).
     */
    public function allPayments(): HasMany
    {
        return $this->hasMany(SalaryAdvancePayment::class);
    }

    /**
     * Everything repaid on the advance: what the old system had already collected on an advance carried over from it
     * (`opening_paid`, 0 for every other advance) plus the repayments recorded here.
     */
    protected function paidAmount(): Attribute
    {
        return Attribute::get(fn (): float => (float) $this->opening_paid + (float) ($this->payments_sum_amount ?? $this->payments()->sum('amount')));
    }

    protected function remainingAmount(): Attribute
    {
        return Attribute::get(fn (): float => max(0, (float) $this->total_payable - $this->paid_amount));
    }
}
