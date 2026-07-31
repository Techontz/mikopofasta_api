<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Treasury\Enums\Currency;
use App\Enums\ActiveStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Backend spec §2.2 — `bank_accounts`.
 *
 * Each one owns exactly one 8xxx chart account, created with it.
 *
 * This used to be read-only — "the frontend has no bank-account CRUD screen
 * (readiness report gap 3), so this is seeded and read, never managed through
 * an endpoint". That gap is closed: Bank → Register Account manages these now,
 * and the four fields that screen collects and this table lacked have been
 * added.
 *
 * `opening_balance` is not a balance. The balance is the 8xxx chart account's,
 * derived from journal lines like every other balance here; this is the figure
 * the opening entry posted, kept so the Account Balance screen can show what an
 * account started with beside what it holds now.
 *
 * @property int $id
 * @property string $bank_name
 * @property string $account_number
 * @property string $account_name
 * @property int|null $branch_id
 * @property Currency $currency
 * @property string $opening_balance
 * @property string|null $description
 * @property int|null $chart_account_id
 * @property ActiveStatus $status
 */
class BankAccount extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'bank_name', 'account_number', 'account_name', 'branch_id', 'currency',
        'opening_balance', 'description', 'chart_account_id', 'status', 'created_by',
    ];

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_account_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What the account holds now, from the ledger.
     *
     * Requires `chartAccount.balances` to be loaded. Returns zero for an
     * account whose chart row is missing, which cannot happen for a row this
     * application created — the two are made in one transaction — but can for
     * one seeded before that was true.
     */
    public function currentBalance(): Money
    {
        return $this->chartAccount?->cachedBalance() ?? Money::zero();
    }

    public function openingBalance(): Money
    {
        return Money::of($this->opening_balance);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ActiveStatus::class,
            'currency' => Currency::class,
            'opening_balance' => 'decimal:2',
        ];
    }
}
