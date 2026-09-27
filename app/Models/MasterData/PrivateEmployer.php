<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrivateEmployer extends MasterDataModel
{
    public const PARENT_COLUMN = 'private_sector_id';

    public const PARENT_MODEL = PrivateSector::class;

    protected $table = 'private_employers';

    public function privateSector(): BelongsTo
    {
        return $this->belongsTo(PrivateSector::class, 'private_sector_id');
    }
}
