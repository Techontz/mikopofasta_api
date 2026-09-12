<?php

declare(strict_types=1);

namespace App\Http\Requests\Treasury;

use App\Models\BankAccount;

/**
 * The same form, editing.
 *
 * `openingBalance` is accepted and ignored by UpdateBankAccountAction: it is a
 * figure an entry already posted, and changing the number without reversing the
 * entry would put the account's own screen at odds with the ledger.
 *
 * The one behavioural difference is uniqueness: an account must be allowed to
 * keep its own number. Saving NMB 2011098765400 without touching the number
 * must not fail on the grounds that NMB 2011098765400 already exists — it is
 * the same row.
 */
final class UpdateBankAccountRequest extends StoreBankAccountRequest
{
    protected function ignoredAccountId(): ?int
    {
        $account = $this->route('bankAccount');

        return $account instanceof BankAccount ? (int) $account->getKey() : null;
    }
}
