<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActiveStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Backend spec §2.2 — `bank_accounts`.
 *
 * Each one owns exactly one 8xxx chart account, created with it. Backend
 * support only: the frontend has no bank-account CRUD screen (readiness report
 * gap 3), so this is seeded and read, never managed through an endpoint.
 *
 * @property int $id
 * @property string $bank_name
 * @property string $account_number
 * @property string $account_name
 * @property int|null $chart_account_id
 * @property ActiveStatus $status
 */
class BankAccount extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['bank_name', 'account_number', 'account_name', 'chart_account_id', 'status'];

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_account_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => ActiveStatus::class];
    }
}
