<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Course extends MasterDataModel
{
    public const PARENT_COLUMN = 'college_id';

    public const PARENT_MODEL = College::class;

    protected $table = 'courses';

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class, 'college_id');
    }
}
