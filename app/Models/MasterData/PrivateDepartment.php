<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A unit (Kitengo / Idara) within a private sector.
 *
 * Parented, so it is loaded one sector at a time rather than whole —
 * the same cascade the address step runs for region → district. See
 * MasterDataRegistry::PARENT_COLUMNS.
 *
 * @property int $private_sector_id
 */
final class PrivateDepartment extends MasterDataModel
{
    protected $table = 'private_departments';

    /** @var list<string> */
    protected $fillable = ['private_sector_id', 'code', 'name', 'description', 'sort_order', 'is_active', 'created_by'];

    /**
     * @return BelongsTo<PrivateSector, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(PrivateSector::class, 'private_sector_id');
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForParent(Builder $query, ?int $id): Builder
    {
        return $id === null ? $query : $query->where('private_sector_id', $id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), ['private_sector_id' => 'integer']);
    }
}
