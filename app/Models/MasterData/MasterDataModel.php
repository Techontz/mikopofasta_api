<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The behaviour every admin-managed lookup list shares.
 *
 * Nine tables have the same shape and the same rules, so they have one base
 * class rather than nine near-identical models. A subclass only declares its
 * table; everything below is common.
 *
 * The point of these existing at all is that no dropdown value lives in the
 * frontend. A list entry is created, renamed, reordered, disabled and
 * soft-deleted from the Administration module, and the registration form reads
 * whatever is active at the moment it loads.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int|null $sort_order
 * @property bool $is_active
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
abstract class MasterDataModel extends Model
{
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'description', 'sort_order', 'is_active', 'created_by'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What a form may offer: active entries, in the order the business chose.
     *
     * `sort_order` first so a list can put its common choice at the top —
     * several of the legacy dropdowns are ordered by frequency, not
     * alphabetically — then by name so entries without an explicit order are
     * still predictable rather than arriving in insertion order.
     */
    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderByRaw('sort_order IS NULL, sort_order')->orderBy('name');
    }

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
}
