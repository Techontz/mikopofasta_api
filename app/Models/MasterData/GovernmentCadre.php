<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GovernmentCadre extends MasterDataModel
{
    public const PARENT_COLUMN = 'government_department_id';

    public const PARENT_MODEL = GovernmentDepartment::class;

    protected $table = 'government_cadres';

    public function governmentDepartment(): BelongsTo
    {
        return $this->belongsTo(GovernmentDepartment::class, 'government_department_id');
    }
}
