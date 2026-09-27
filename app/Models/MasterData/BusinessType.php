<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessType extends MasterDataModel
{
    public const PARENT_COLUMN = 'business_sector_id';

    public const PARENT_MODEL = BusinessSector::class;

    protected $table = 'business_types';

    public function businessSector(): BelongsTo
    {
        return $this->belongsTo(BusinessSector::class, 'business_sector_id');
    }
}
