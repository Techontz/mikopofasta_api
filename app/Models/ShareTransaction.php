<?php

namespace App\Models;

use App\Enums\ShareTransactionType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Immutable share movement: `shares` move from `from_share_holder_id` (null = unissued) to `to_share_holder_id`
 * (null = cancelled). Corrections are reversal rows; only the status (completed → reversed) and the supporting
 * document may change after recording.
 */
class ShareTransaction extends Model
{
    use Auditable;

    public const COMPLETED = 'completed';

    public const REVERSED = 'reversed';

    /** Paid in cash/bank now: Dr Company Bank/Cash / Cr Share Capital through the capital contribution service. */
    public const TREATMENT_PAID = 'paid';

    /** Linked to a capital contribution already recorded (and already posted) — no new journal. */
    public const TREATMENT_LINKED = 'linked_contribution';

    /** No cash (bonus, founders' allocation without payment, adjustment) — no journal. */
    public const TREATMENT_NO_CASH = 'no_cash';

    /** Private disk holding supporting documents (served only through the authorised API). */
    public const DISK = 'local';

    /**
     * @var list<string>
     */
    private const MUTABLE_COLUMNS = ['status', 'document_path', 'document_name', 'updated_at'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ShareTransactionType::class,
            'shares' => 'integer',
            'share_value' => 'decimal:2',
            'price_per_share' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'transacted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ShareTransaction $transaction): void {
            if (array_diff(array_keys($transaction->getDirty()), self::MUTABLE_COLUMNS) !== []) {
                throw new LogicException('Share transactions are immutable; post a reversal instead.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Share transactions cannot be deleted; post a reversal instead.'));
    }

    public function fromShareHolder(): BelongsTo
    {
        return $this->belongsTo(ShareHolder::class, 'from_share_holder_id');
    }

    public function toShareHolder(): BelongsTo
    {
        return $this->belongsTo(ShareHolder::class, 'to_share_holder_id');
    }

    public function capital(): BelongsTo
    {
        return $this->belongsTo(Capital::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'performed_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /**
     * Change in total issued shares caused by this row.
     */
    public function issuedDelta(): int
    {
        return ($this->to_share_holder_id !== null ? $this->shares : 0) - ($this->from_share_holder_id !== null ? $this->shares : 0);
    }
}
