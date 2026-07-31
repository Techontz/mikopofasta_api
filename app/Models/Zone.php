<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Backend spec §2.2 — `zones`.
 *
 * A commission/oversight grouping over branches, independent of the
 * geographic region axis (§12). Drives Zone Manager scope (§13) and the zone
 * commission override (§11).
 *
 * @property int $id
 * @property string $name
 * @property int|null $zone_manager_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Zone extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'zone_manager_id'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'zone_manager_id');
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * Users scoped to this zone (spec §13 Zone Manager scope) — distinct from
     * `manager()`, which is the single person heading it.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
