<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Common shape of a master-data list (id, code, name, description, sort_order, is_active, created_by).
 * Parented lists set PARENT_COLUMN and PARENT_MODEL.
 */
abstract class MasterDataModel extends Model
{
    use SoftDeletes;

    /** Foreign key to the parent list, or null for a flat list. */
    public const PARENT_COLUMN = null;

    /** @var class-string<MasterDataModel>|null */
    public const PARENT_MODEL = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
