<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Relations\HasMany;

class College extends MasterDataModel
{
    protected $table = 'colleges';

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'college_id');
    }
}
