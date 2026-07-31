<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.7 — `account_balances`.
 *
 * A materialized CACHE, never a source of truth. Every row is recomputed from
 * `journal_entry_lines` by AccountResolver::refreshBalances(), so it can be
 * dropped and rebuilt at any moment without losing information.
 *
 * @property int $id
 * @property int $account_id
 * @property int|null $branch_id
 * @property string $debit_total
 * @property string $credit_total
 * @property string $balance
 * @property CarbonImmutable $last_updated_at
 */
class AccountBalance extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'account_id', 'branch_id', 'debit_total', 'credit_total', 'balance', 'last_updated_at',
    ];

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function balanceMoney(): Money
    {
        return Money::of($this->balance);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_updated_at' => 'datetime'];
    }
}
