<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A grade or post (Cheo) inside a government department.
 *
 * Parented, so it is loaded one department at a time rather than whole —
 * the same cascade the address step runs for region → district. See
 * MasterDataRegistry::PARENT_COLUMNS.
 *
 * @property int $government_department_id
 */
final class GovernmentCadre extends MasterDataModel
{
    protected $table = 'government_cadres';

    /** @var list<string> */
    protected $fillable = ['government_department_id', 'code', 'name', 'description', 'sort_order', 'is_active', 'created_by'];

    /**
     * @return BelongsTo<GovernmentDepartment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(GovernmentDepartment::class, 'government_department_id');
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForParent(Builder $query, ?int $id): Builder
    {
        return $id === null ? $query : $query->where('government_department_id', $id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), ['government_department_id' => 'integer']);
    }
}
