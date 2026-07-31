<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Backend spec §2.2 — `regions`.
 *
 * Reference data with no soft delete, per the spec. Serves two independent
 * purposes: the top of the customer address hierarchy (§2.4), and the
 * Regional Manager oversight axis via `branches.region_id` (§12).
 *
 * @property int $id
 * @property string $name
 */
class Region extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name'];

    /**
     * @return HasMany<District, $this>
     */
    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
