<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Repayments\Enums\CashDepositStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.6 — `cash_deposits`.
 *
 * §7: this table "exists precisely because teller cash-in-hand and
 * bank-confirmed cash are two different trust states". A cash payment stays
 * `pending_verification` until a deposit slip is reconciled against it.
 *
 * @property int $id
 * @property int $teller_id
 * @property int $branch_id
 * @property string $amount
 * @property int $bank_account_id
 * @property string|null $deposit_slip_path
 * @property CashDepositStatus $status
 * @property list<int>|null $matched_payment_ids
 * @property int|null $reconciled_by
 * @property CarbonImmutable|null $reconciled_at
 * @property int|null $journal_entry_id
 */
class CashDeposit extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'teller_id', 'branch_id', 'amount', 'bank_account_id', 'deposit_slip_path',
        'status', 'matched_payment_ids', 'reconciled_by', 'reconciled_at', 'journal_entry_id',
    ];

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teller_id');
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CashDepositStatus::class,
            'matched_payment_ids' => 'array',
            'reconciled_at' => 'datetime',
        ];
    }
}
