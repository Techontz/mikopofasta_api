<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money that came back after a loan was written off — §5's Recovered Loans.
 *
 * Not a repayment. The loan it settles is no longer on the book, so §5 credits
 * Recovered Loans rather than Loan Receivable: crediting receivable would drive
 * the account negative, since the write-off already cleared it.
 *
 * The client's commission note turns on this distinction — "mikopo iliyodefault
 * ikirudishwa kutakuwa na commission kubwa zaidi", recovered defaults earn a
 * higher commission — so recoveries have to be separable from ordinary
 * collections in the ledger, not merely in a report.
 *
 * @property int $id
 * @property int $loan_id
 * @property int $write_off_id
 * @property string $amount
 * @property int|null $payment_id
 * @property int|null $bank_account_id
 * @property string|null $narrative
 * @property int $recorded_by
 * @property int|null $journal_entry_id
 */
class Recovery extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'loan_id', 'write_off_id', 'amount', 'payment_id', 'bank_account_id',
        'narrative', 'recorded_by', 'journal_entry_id',
    ];

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<WriteOff, $this> */
    public function writeOff(): BelongsTo
    {
        return $this->belongsTo(WriteOff::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }
}
