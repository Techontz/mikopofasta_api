<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the seven headquarters accounts.
 *
 * The set is fixed and comes from the legacy system's own balance screen — see
 * Database\Seeders\Legacy\LegacySource::hqAccounts(). Nothing creates an eighth
 * at runtime; there is no HQ account CRUD, because the legacy system has none.
 *
 * @property int $id
 * @property string $name
 * @property string $balance
 */
class HqAccount extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'balance'];

    /**
     * Transfers out of this account.
     *
     * @return HasMany<HqAccountTransfer, $this>
     */
    public function transfersOut(): HasMany
    {
        return $this->hasMany(HqAccountTransfer::class, 'from_account_id');
    }

    /**
     * Transfers into this account.
     *
     * @return HasMany<HqAccountTransfer, $this>
     */
    public function transfersIn(): HasMany
    {
        return $this->hasMany(HqAccountTransfer::class, 'to_account_id');
    }

    /**
     * The balance as an exact amount rather than a string, so callers that add
     * these up cannot reach for float arithmetic to do it.
     */
    public function balance(): Money
    {
        return Money::of($this->balance);
    }
}
