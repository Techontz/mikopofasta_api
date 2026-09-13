<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A course (Kozi) offered by a college.
 *
 * Parented, so it is loaded one college at a time rather than whole —
 * the same cascade the address step runs for region → district. See
 * MasterDataRegistry::PARENT_COLUMNS.
 *
 * @property int $college_id
 */
final class Course extends MasterDataModel
{
    protected $table = 'courses';

    /** @var list<string> */
    protected $fillable = ['college_id', 'code', 'name', 'description', 'sort_order', 'is_active', 'created_by'];

    /**
     * @return BelongsTo<College, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(College::class, 'college_id');
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForParent(Builder $query, ?int $id): Builder
    {
        return $id === null ? $query : $query->where('college_id', $id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), ['college_id' => 'integer']);
    }
}
