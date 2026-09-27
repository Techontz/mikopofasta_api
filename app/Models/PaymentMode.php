<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class PaymentMode extends Model
{
    use Auditable;

    protected $guarded = ['id'];
}
