<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy Mtaa (street / village) names keyed by the old ward code, collected by the previous registration flow.
 */
class Street extends Model
{
    protected $guarded = ['id'];
}
