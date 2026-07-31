<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Expenses\Enums\ExpenseScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named thing the company spends against — Expenses → Register Branch
 * Expenses, and Headquarters Expenses → Register Expenses.
 *
 * Owns exactly one 6xxx chart account (ACCOUNT OVERVIEW §G: "Kila category =
 * Ledger yake"), which is why every expense breakdown in the reporting spec is
 * a grouped ledger query rather than a scan of description text.
 *
 * @property int $id
 * @property string $name
 * @property ExpenseScope $scope
 * @property int $chart_account_id
 * @property int|null $created_by
 */
class ExpenseCategory extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['name', 'scope', 'chart_account_id', 'created_by'];

    /** @return BelongsTo<ChartOfAccount, $this> */
    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ExpenseRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(ExpenseRequest::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope' => ExpenseScope::class,
        ];
    }
}
