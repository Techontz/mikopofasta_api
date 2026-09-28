<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A "File" report printed by the old system and imported as history (records only — no loans, cash or ledger entries).
 * printed_totals keeps the TOTAL row as printed, keyed by month number; it is not recomputed from the rows.
 */
class HistoricalFileReport extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'printed_totals' => 'array',
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
     * @return HasMany<HistoricalFileRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(HistoricalFileRecord::class);
    }

    /**
     * How the report is named wherever its rows appear, e.g. "FILE REPORT - KAKONKO / DATE : 2022 (KAKONKO.pdf)".
     */
    public function label(): string
    {
        return "{$this->title} ({$this->source_document})";
    }
}
