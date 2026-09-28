<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Relations\HasMany;

class GovernmentBody extends MasterDataModel
{
    protected $table = 'government_bodies';

    public function governmentDepartments(): HasMany
    {
        return $this->hasMany(GovernmentDepartment::class, 'government_body_id');
    }
}
