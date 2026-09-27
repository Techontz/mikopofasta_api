<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A "PENARTY REPORT" printed by the old system and imported as history (records only — no penalty, cash or ledger
 * entry stands behind it). printed_total keeps the TOTAL row as printed; it is not recomputed from the rows.
 */
class HistoricalPenaltyReport extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'printed_on' => 'date',
            'printed_total' => 'decimal:2',
            'imported_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<HistoricalPenaltyRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(HistoricalPenaltyRecord::class);
    }

    /**
     * How the report is named wherever its rows appear, e.g. "PENARTY REPORT - KAKONKO (kakonko Penalty Report 2026-09-20.pdf)".
     */
    public function label(): string
    {
        return "{$this->title} ({$this->source_document})";
    }
}
