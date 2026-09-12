<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How distributable profit splits — Capital → Profit Distribution.
 *
 * ACCOUNT OVERVIEW §I.16 fixes the split at 70% Principal (reinvestment) and
 * 30% Dividend (shareholders), and that is what this is seeded with. It is a
 * stored policy rather than a constant because the handwritten note describes
 * it as a proportion to be decided, not a law.
 *
 * Singleton: exactly one row, the same pattern `ReserveSetting` uses. The
 * balances themselves live in the ledger — accounts 1100 and 7100; this is the
 * policy percentage, not the money.
 *
 * @property int $id
 * @property string $reinvestment_percentage
 * @property string $dividend_percentage
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class DistributionSetting extends Model
{
    /** @var list<string> */
    protected $fillable = ['reinvestment_percentage', 'dividend_percentage', 'updated_by'];

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The one row, created on first read so no screen has to cope with its
     * absence and no second row can ever be inserted.
     *
     * The defaults repeat the documented split: an installation whose
     * migration seed was rolled back still closes its books the way the
     * document says, rather than distributing nothing.
     */
    public static function singleton(): self
    {
        return static::query()->firstOrCreate([], [
            'reinvestment_percentage' => 70,
            'dividend_percentage' => 30,
        ]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reinvestment_percentage' => 'decimal:2',
            'dividend_percentage' => 'decimal:2',
        ];
    }
}
