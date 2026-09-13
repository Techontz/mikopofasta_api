<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A specific trade (Aina Maalum ya Biashara) within a business sector.
 *
 * Parented, so it is loaded one business sector at a time rather than whole —
 * the same cascade the address step runs for region → district. See
 * MasterDataRegistry::PARENT_COLUMNS.
 *
 * @property int $business_sector_id
 */
final class BusinessType extends MasterDataModel
{
    protected $table = 'business_types';

    /** @var list<string> */
    protected $fillable = ['business_sector_id', 'code', 'name', 'description', 'sort_order', 'is_active', 'created_by'];

    /**
     * @return BelongsTo<BusinessSector, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(BusinessSector::class, 'business_sector_id');
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForParent(Builder $query, ?int $id): Builder
    {
        return $id === null ? $query : $query->where('business_sector_id', $id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), ['business_sector_id' => 'integer']);
    }
}
