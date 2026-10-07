<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named list of phone numbers (staff, agents, partners, people who are not customers) that announcements can be sent to.
 */
class SmsContactGroup extends Model
{
    protected $guarded = ['id'];

    public function members(): HasMany
    {
        return $this->hasMany(SmsContactGroupMember::class)->orderBy('id');
    }
}
