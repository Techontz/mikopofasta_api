<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A movement between two headquarters accounts.
 *
 * Mirrors the legacy Headquater Transaction screens: a request names a source
 * account, a destination and an amount, and is later approved. The legacy
 * status vocabulary has not been captured, so `status` is a plain string here
 * rather than an enum — an enum would have to enumerate values, and the whole
 * point is that we do not yet know them.
 *
 * @property int $id
 * @property int $from_account_id
 * @property int $to_account_id
 * @property string $amount
 * @property string|null $charger
 * @property string|null $staff_name
 * @property string $status
 * @property CarbonImmutable $requested_on
 * @property CarbonImmutable|null $approved_on
 */
class HqAccountTransfer extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'from_account_id', 'to_account_id', 'amount',
        'charger', 'staff_name', 'status', 'requested_on', 'approved_on',
    ];

    /**
     * @return BelongsTo<HqAccount, $this>
     */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(HqAccount::class, 'from_account_id');
    }

    /**
     * @return BelongsTo<HqAccount, $this>
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(HqAccount::class, 'to_account_id');
    }

    public function amount(): Money
    {
        return Money::of($this->amount);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_on' => 'immutable_date',
            'approved_on' => 'immutable_date',
        ];
    }
}
