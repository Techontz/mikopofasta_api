<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One movement of a loan's advance credit.
 *
 * Positive credits the borrower, negative consumes the credit. The balance is
 * the sum of the movements, which is what makes it auditable — see the
 * migration for why this is a ledger rather than a column.
 *
 * @property int $id
 * @property int $loan_id
 * @property int|null $payment_id
 * @property string $amount
 * @property string $balance_after
 * @property string $kind
 * @property string|null $narrative
 * @property int|null $journal_entry_id
 */
class LoanAdvance extends Model
{
    public const string KIND_CREDIT = 'credit';

    public const string KIND_CONSUMPTION = 'consumption';

    public const string KIND_REFUND = 'refund';

    /** @var list<string> */
    protected $fillable = [
        'loan_id', 'payment_id', 'amount', 'balance_after',
        'kind', 'narrative', 'journal_entry_id', 'created_by',
    ];

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    /**
     * What a loan currently holds in advance.
     *
     * Summed from the movements rather than read from a balance column, so it
     * cannot drift from its own history. One indexed aggregate — this is called
     * on every allocation, and a portfolio of 100,000 loans cannot afford it to
     * be anything more.
     */
    public static function balanceFor(int $loanId): Money
    {
        $sum = DB::table('loan_advances')->where('loan_id', $loanId)->sum('amount');

        return Money::of(number_format((float) $sum, 2, '.', ''));
    }

    /**
     * Advance balances for many loans at once, keyed by loan id.
     *
     * The N+1 guard. A report or a list screen asking for one balance per loan
     * would issue one query per loan; this answers for the whole page in one.
     *
     * @param list<int> $loanIds
     * @return array<int, Money>
     */
    public static function balancesFor(array $loanIds): array
    {
        if ($loanIds === []) {
            return [];
        }

        $rows = DB::table('loan_advances')
            ->select('loan_id')
            ->selectRaw('SUM(amount) AS total')
            ->whereIn('loan_id', $loanIds)
            ->groupBy('loan_id')
            ->get();

        $balances = [];

        foreach ($rows as $row) {
            $balances[(int) $row->loan_id] = Money::of(number_format((float) $row->total, 2, '.', ''));
        }

        return $balances;
    }
}
