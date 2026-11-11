<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Linda Device Lock webhook received, keyed by Linda's event id so a redelivered event is applied only once.
 */
class LindaWebhookEvent extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
