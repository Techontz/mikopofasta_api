<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frontend addition (types/customer-note.ts) — CRM-style free-text notes
 * staff attach to a customer profile.
 *
 * @property int $id
 * @property int $customer_id
 * @property int $author_id
 * @property string $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CustomerNote extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['customer_id', 'author_id', 'note'];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
