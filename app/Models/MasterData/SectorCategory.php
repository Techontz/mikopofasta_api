<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cadre within a sector — Teachers within TAMISEMI.
 *
 * The only lookup list with a parent, which is why it is the only one that
 * declares anything beyond its table name. The registration form loads it a
 * sector at a time, exactly as the address step loads districts a region at a
 * time, so `scopeForSector` is the whole of the extra behaviour.
 *
 * @property int $sector_id
 * @property-read Sector|null $sector
 */
final class SectorCategory extends MasterDataModel
{
    protected $table = 'sector_categories';

    /** @var list<string> */
    protected $fillable = ['sector_id', 'code', 'name', 'description', 'sort_order', 'is_active', 'created_by'];

    /**
     * @return BelongsTo<Sector, $this>
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForSector(Builder $query, ?int $sectorId): Builder
    {
        return $sectorId === null ? $query : $query->where('sector_id', $sectorId);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), ['sector_id' => 'integer']);
    }
}
