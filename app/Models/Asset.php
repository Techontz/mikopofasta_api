<?php

namespace App\Models;

use App\Enums\Account;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A contributed asset in the Asset Registry (Capital → Assets). One row per asset capital contribution.
 *
 * The contribution value, contributor, contribution date and valuation snapshot never change; `current_value` changes only
 * through a recorded revaluation, `branch_id` through a transfer and `status` through a status change — each written to
 * {@see AssetEvent} history.
 */
class Asset extends Model
{
    use Auditable;

    /**
     * Private disk holding asset documents and photos (served only through the authorised API).
     */
    public const DISK = 'local';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_value' => 'decimal:2',
            'contribution_value' => 'decimal:2',
            'current_value' => 'decimal:2',
            'contributed_on' => 'date',
            'valuation_date' => 'date',
            'specifications' => 'array',
        ];
    }

    public function capital(): BelongsTo
    {
        return $this->belongsTo(Capital::class);
    }

    public function shareHolder(): BelongsTo
    {
        return $this->belongsTo(ShareHolder::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recorded_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssetEvent::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AssetDocument::class);
    }

    public function ledgerAccount(): ?Account
    {
        return Account::tryFrom((string) $this->ledger_account);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, config('assets.terminal_statuses'), true);
    }
}
