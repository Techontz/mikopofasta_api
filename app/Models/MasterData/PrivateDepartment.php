<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrivateDepartment extends MasterDataModel
{
    public const PARENT_COLUMN = 'private_sector_id';

    public const PARENT_MODEL = PrivateSector::class;

    protected $table = 'private_departments';

    public function privateSector(): BelongsTo
    {
        return $this->belongsTo(PrivateSector::class, 'private_sector_id');
    }

    public function privateCadres(): HasMany
    {
        return $this->hasMany(PrivateCadre::class, 'private_department_id');
    }
}
