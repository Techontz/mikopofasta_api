<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use App\Support\Percentage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.9 — `zone_commission_distributions`.
 *
 * A zone manager's override: a percentage of the combined pools of the
 * branches they oversee (§11). It is expensed as part of that manager's own
 * payroll recognition entry rather than posted separately, so
 * `journal_entry_id` points at the same entry their salary does — the money is
 * recognised once.
 *
 * @property int $id
 * @property int $zone_id
 * @property string $period
 * @property string $total_pool_base
 * @property string $override_percentage
 * @property string $override_amount
 * @property int|null $journal_entry_id
 */
class ZoneCommissionDistribution extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'zone_id', 'period', 'total_pool_base', 'override_percentage',
        'override_amount', 'journal_entry_id',
    ];

    /**
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function overrideAmount(): Money
    {
        return Money::of($this->override_amount);
    }

    public function totalPoolBase(): Money
    {
        return Money::of($this->total_pool_base);
    }

    public function overridePercentage(): Percentage
    {
        return Percentage::of($this->override_percentage);
    }
}
