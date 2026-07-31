<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.2 — `streets`. The leaf of the address hierarchy.
 *
 * @property int $id
 * @property int $ward_id
 * @property string $name
 */
class Street extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['ward_id', 'name'];

    /**
     * @return BelongsTo<Ward, $this>
     */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }
}
