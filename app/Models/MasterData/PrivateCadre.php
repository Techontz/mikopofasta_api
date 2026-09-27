<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrivateCadre extends MasterDataModel
{
    public const PARENT_COLUMN = 'private_department_id';

    public const PARENT_MODEL = PrivateDepartment::class;

    protected $table = 'private_cadres';

    public function privateDepartment(): BelongsTo
    {
        return $this->belongsTo(PrivateDepartment::class, 'private_department_id');
    }
}
