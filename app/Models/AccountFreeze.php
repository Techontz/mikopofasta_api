<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FreezableType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.1 — `account_freezes`.
 *
 * Append-and-close: freezing inserts a row, unfreezing stamps `unfrozen_at` on
 * the open one. The full history is retained, so "how many times has this
 * customer been frozen, and why" is answerable — which is the point of having
 * the table rather than a boolean on the customer.
 *
 * @property int $id
 * @property FreezableType $freezable_type
 * @property int $freezable_id
 * @property string $reason
 * @property int $frozen_by
 * @property CarbonImmutable $frozen_at
 * @property int|null $unfrozen_by
 * @property CarbonImmutable|null $unfrozen_at
 */
class AccountFreeze extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'freezable_type', 'freezable_id', 'reason',
        'frozen_by', 'frozen_at', 'unfrozen_by', 'unfrozen_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function frozenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'frozen_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function unfrozenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unfrozen_by');
    }

    public function isOpen(): bool
    {
        return $this->unfrozen_at === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'freezable_type' => FreezableType::class,
            'frozen_at' => 'datetime',
            'unfrozen_at' => 'datetime',
        ];
    }
}
