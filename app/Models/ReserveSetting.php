<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The share of the portfolio held in reserve — Settings → Reserve Setting.
 *
 * Singleton: exactly one row, the same pattern company_profiles uses. The
 * dashboard's reserve figure still comes from ledger account 3000; this is the
 * policy percentage, not the balance.
 *
 * @property int $id
 * @property string $percentage
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ReserveSetting extends Model
{
    /** @var list<string> */
    protected $fillable = ['percentage', 'updated_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['percentage' => 'decimal:3'];
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The one row, created on first read so no screen has to cope with its
     * absence and no second row can ever be inserted.
     */
    public static function singleton(): self
    {
        return static::query()->firstOrCreate([], ['percentage' => 0]);
    }
}
