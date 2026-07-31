<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Ledger\Enums\AccountType;
use App\Enums\ActiveStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Backend spec §2.7 — `chart_of_accounts`.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property int|null $parent_account_id
 * @property bool $is_system
 * @property int|null $branch_id
 * @property ActiveStatus $status
 */
class ChartOfAccount extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'type', 'parent_account_id', 'is_system', 'branch_id', 'status'];

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id');
    }

    /**
     * @return HasMany<AccountBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(AccountBalance::class, 'account_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The account-wide cached balance: every branch row summed.
     *
     * `account_balances` is keyed (account_id, branch_id) and holds one row per
     * branch that has touched the account, plus a NULL-branch row for lines
     * that carry no branch at all (a capital injection, say). There is no
     * separate "total" row to read — summing is what makes this account-wide,
     * and reading only the NULL row would report zero for every account whose
     * activity is branch-tagged, which is nearly all of them.
     */
    public function cachedBalance(): Money
    {
        return Money::sum($this->balances->map(fn (AccountBalance $b): Money => $b->balanceMoney()));
    }

    /**
     * The cached balance for one branch — the figure a branch-scoped ledger
     * screen shows. Pass null for the branch-less remainder.
     */
    public function cachedBalanceFor(?int $branchId): Money
    {
        $row = $this->balances->firstWhere('branch_id', $branchId);

        return $row === null ? Money::zero() : $row->balanceMoney();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'status' => ActiveStatus::class,
            'is_system' => 'boolean',
        ];
    }
}
