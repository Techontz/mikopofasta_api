<?php

declare(strict_types=1);

namespace App\Domain\Accounting\DTOs;

use App\Domain\Ledger\Enums\AccountType;
use App\Support\Money;

/**
 * One income or expense account's net movement in a period, for one branch.
 *
 * `net` is already signed on the account's normal side — a credit figure for
 * income, a debit figure for expense — so a caller never has to remember which
 * way round an account runs. It can be negative, and that is meaningful: a
 * refunded fee is negative income, not an expense.
 */
final readonly class AccountMovement
{
    public function __construct(
        public int $accountId,
        public ?int $branchId,
        public AccountType $type,
        public Money $net,
    ) {}

    public function isIncome(): bool
    {
        return $this->type === AccountType::Income;
    }
}
