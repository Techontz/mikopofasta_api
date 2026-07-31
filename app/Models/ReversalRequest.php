<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Ledger\Enums\ReversalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.7 — `reversal_requests`.
 *
 * §14: requesting and approving a reversal are different permissions held by
 * different roles (Branch Manager can request; only Finance or Super Admin
 * approve). This row records who did which.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $requested_by
 * @property string $reason
 * @property int|null $approved_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property int|null $reversal_entry_id
 * @property ReversalStatus $status
 */
class ReversalRequest extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'journal_entry_id', 'requested_by', 'reason',
        'approved_by', 'decided_at', 'decision_note', 'reversal_entry_id', 'status',
    ];

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPending(): bool
    {
        return $this->status === ReversalStatus::Pending;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => ReversalStatus::class, 'decided_at' => 'datetime'];
    }
}
