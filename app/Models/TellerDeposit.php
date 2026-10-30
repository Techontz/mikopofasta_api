<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Bank deposit slip of teller cash, reconciled by Finance against the bank statement.
 */
class TellerDeposit extends Model
{
    public const DISK = 'local';

    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_MISMATCH = 'mismatch';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'statement_amount' => 'decimal:2',
            'deposit_date' => 'date',
            'verified_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'verified_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'confirmed_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Sum of the teller cash receipts on this slip.
     */
    public function expectedAmount(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    /**
     * Store (or replace) the bank receipt on the private disk (enhancement §8.3).
     */
    public function attachReceipt(UploadedFile $file): void
    {
        $previous = $this->receipt_file;
        $this->update([
            'receipt_file' => $file->store("teller-deposit-receipts/{$this->company_id}", self::DISK),
            'receipt_file_name' => mb_substr(basename($file->getClientOriginalName()), 0, 191),
            'receipt_mime' => mb_substr((string) $file->getMimeType(), 0, 100),
        ]);

        if ($previous) {
            Storage::disk(self::DISK)->delete($previous);
        }
    }

    public function hasReceipt(): bool
    {
        return $this->receipt_file !== null && Storage::disk(self::DISK)->exists($this->receipt_file);
    }
}
