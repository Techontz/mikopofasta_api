<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * What the Reserve fund actually holds — Decision Register D1.
 *
 * Read from `journal_entry_lines`, never from `account_balances`. §2.7 calls
 * that table a materialized cache, and the one question this class answers is
 * whether there is enough money to release. A guard that trusted a cache it
 * could not verify would be no guard at all.
 *
 * Reserve is credit-normal: appropriations credit it, utilisations debit it,
 * and the balance is what remains between them.
 */
final class ReserveBalanceReader
{
    public function __construct(private readonly AccountResolver $accounts) {}

    public function balance(): Money
    {
        $accountId = $this->accounts->systemId(SystemAccountCode::Reserve);

        $row = DB::table('journal_entry_lines')
            ->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit_amount), 0) AS debit_total, COALESCE(SUM(credit_amount), 0) AS credit_total')
            ->first();

        $debit = Money::of((string) ($row->debit_total ?? '0.00'));
        $credit = Money::of((string) ($row->credit_total ?? '0.00'));

        return $credit->subtract($debit);
    }

    /**
     * The balance less everything already approved but not yet posted.
     *
     * In practice approval and posting happen in one transaction, so this
     * equals `balance()`. It exists as a named concept because the moment
     * approval is ever split from disbursement — which D1's Admin step invites
     * — the two answers diverge, and the guard must use this one.
     */
    public function available(): Money
    {
        return $this->balance();
    }
}
