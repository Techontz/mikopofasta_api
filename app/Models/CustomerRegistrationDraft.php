<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An unfinished registration — see the 2026_08_26 migration for why this is a
 * row rather than a `localStorage` key, and why it is not a Customer.
 *
 * @property int $id
 * @property int $created_by
 * @property int $branch_id
 * @property string $label
 * @property string|null $phone
 * @property array<string, mixed> $payload
 * @property int $step
 * @property int|null $customer_id
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CustomerRegistrationDraft extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'created_by', 'branch_id', 'label', 'phone', 'payload', 'step',
        'customer_id', 'submitted_at',
    ];

    /**
     * Still open — not yet turned into a customer.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('submitted_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'step' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }
}
