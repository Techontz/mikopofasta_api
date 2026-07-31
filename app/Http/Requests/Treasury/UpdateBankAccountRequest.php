<?php

declare(strict_types=1);

namespace App\Http\Requests\Treasury;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * The same form, editing.
 *
 * `openingBalance` is accepted and ignored by UpdateBankAccountAction: it is a
 * figure an entry already posted, and changing the number without reversing the
 * entry would put the account's own screen at odds with the ledger.
 */
final class UpdateBankAccountRequest extends StoreBankAccountRequest
{
    protected function accountNumberUniqueness(): Unique
    {
        return Rule::unique('bank_accounts', 'account_number')
            ->whereNull('deleted_at')
            ->ignore($this->route('bankAccount'));
    }
}
