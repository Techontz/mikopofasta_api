<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GovernmentDepartment extends MasterDataModel
{
    public const PARENT_COLUMN = 'government_body_id';

    public const PARENT_MODEL = GovernmentBody::class;

    protected $table = 'government_departments';

    public function governmentBody(): BelongsTo
    {
        return $this->belongsTo(GovernmentBody::class, 'government_body_id');
    }

    public function governmentCadres(): HasMany
    {
        return $this->hasMany(GovernmentCadre::class, 'government_department_id');
    }
}
