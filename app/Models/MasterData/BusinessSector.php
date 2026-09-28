<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessSector extends MasterDataModel
{
    protected $table = 'business_sectors';

    public function businessTypes(): HasMany
    {
        return $this->hasMany(BusinessType::class, 'business_sector_id');
    }
}
