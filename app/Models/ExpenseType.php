<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseType extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    public function requests(): HasMany
    {
        return $this->hasMany(ExpenseRequest::class);
    }
}
