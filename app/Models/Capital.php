<?php

namespace App\Models;

use App\Enums\Account;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * One shareholder capital contribution. Rows are never overwritten: every contribution is its own row, posted
 * Dr receiving company account (COMPANY ACCOUNT for CASH, the selected bank account for BANK) / Cr CAPITAL ACCOUNT.
 * Contributions are financial transactions; ownership comes from the share register (share transactions), which may
 * link a contribution to the shares it paid for.
 */
class Capital extends Model
{
    use Auditable;

    /**
     * Private disk holding uploaded receipts (served only through the authorised API).
     */
    public const DISK = 'local';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'contributed_at' => 'datetime',
            'reversed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REJECTED = 'rejected';

    /** Withdrawn by the shareholder who submitted it while still pending (nothing was ever posted). */
    public const STATUS_CANCELLED = 'cancelled';

    /** `source` of a contribution submitted by the shareholder from the Shareholder Portal (null = recorded by staff). */
    public const SOURCE_SHAREHOLDER_PORTAL = 'shareholder_portal';

    /**
     * Contributions that count as contributed capital: posted (approved) and not reversed. Pending and rejected
     * contributions never count in ownership, contribution totals or dividends (rule 6).
     *
     * @param  Builder<Capital>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('reversed_at')->where('status', self::STATUS_POSTED);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPosted(): bool
    {
        return ($this->status ?? self::STATUS_POSTED) === self::STATUS_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /**
     * The contributed asset of an ASSET contribution.
     */
    public function asset(): HasOne
    {
        return $this->hasOne(Asset::class);
    }

    public function shareHolder(): BelongsTo
    {
        return $this->belongsTo(ShareHolder::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recorded_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rejected_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cancelled_by');
    }

    public function isFromShareholderPortal(): bool
    {
        return $this->source === self::SOURCE_SHAREHOLDER_PORTAL;
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reversed_by');
    }

    /**
     * "COMPANY ACCOUNT", "BANK - NMB" or the fixed-asset account of an asset contribution ("MOTOR VEHICLES");
     * null for legacy rows whose posting could not be traced.
     */
    public function receivingAccountLabel(): ?string
    {
        return match ($this->receiving_account) {
            Account::Company->value => Account::Company->label(),
            Account::Bank->value => trim(Account::Bank->label().' - '.$this->bankAccount?->name, ' -'),
            null => null,
            default => Account::tryFrom($this->receiving_account)?->label(),
        };
    }

    /**
     * Share transactions (issuance / initial allocation) this contribution paid for.
     */
    public function shareTransactions(): HasMany
    {
        return $this->hasMany(ShareTransaction::class);
    }

    /**
     * Store (or replace) the receipt document on the private disk.
     */
    public function attachReceipt(UploadedFile $file): void
    {
        $previous = $this->receipt_file;
        $this->update([
            'receipt_file' => $file->store("capital-receipts/{$this->company_id}", self::DISK),
            'receipt_file_name' => mb_substr(basename($file->getClientOriginalName()), 0, 191),
        ]);

        if ($previous) {
            Storage::disk(self::DISK)->delete($previous);
        }
    }
}
