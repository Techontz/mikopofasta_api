<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Relations\HasMany;

class PrivateSector extends MasterDataModel
{
    protected $table = 'private_sectors';

    public function privateEmployers(): HasMany
    {
        return $this->hasMany(PrivateEmployer::class, 'private_sector_id');
    }

    public function privateDepartments(): HasMany
    {
        return $this->hasMany(PrivateDepartment::class, 'private_sector_id');
    }
}
