<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffLoanCategory extends Model
{
    protected $guarded = ['id'];

    public function staffLoans(): HasMany
    {
        return $this->hasMany(StaffLoan::class);
    }
}
