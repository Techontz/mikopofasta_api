<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Backend spec §2.2 — `wards`.
 *
 * @property int $id
 * @property int $district_id
 * @property string $name
 */
class Ward extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['district_id', 'name'];

    /**
     * @return BelongsTo<District, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /**
     * @return HasMany<Street, $this>
     */
    public function streets(): HasMany
    {
        return $this->hasMany(Street::class);
    }
}
