<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A department (Idara) inside a ministry.
 *
 * Parented, so it is loaded one ministry at a time rather than whole —
 * the same cascade the address step runs for region → district. See
 * MasterDataRegistry::PARENT_COLUMNS.
 *
 * @property int $government_body_id
 */
final class GovernmentDepartment extends MasterDataModel
{
    protected $table = 'government_departments';

    /** @var list<string> */
    protected $fillable = ['government_body_id', 'code', 'name', 'description', 'sort_order', 'is_active', 'created_by'];

    /**
     * @return BelongsTo<GovernmentBody, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(GovernmentBody::class, 'government_body_id');
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForParent(Builder $query, ?int $id): Builder
    {
        return $id === null ? $query : $query->where('government_body_id', $id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), ['government_body_id' => 'integer']);
    }
}
