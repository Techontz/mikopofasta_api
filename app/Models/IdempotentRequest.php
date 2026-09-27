<?php

namespace App\Models;

use App\Http\Middleware\EnsureIdempotentRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * A mutating API request recorded under its `Idempotency-Key` header ({@see EnsureIdempotentRequest}).
 */
class IdempotentRequest extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['response_status' => 'integer'];
    }
}
