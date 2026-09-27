<?php

namespace App\Services\Approvals;

use App\Enums\Account;
use Illuminate\Validation\ValidationException;

/**
 * Rule 3 — the interest reserve stays in the reserve account. Manual outflows from the branch RESERVE fund or the HQ
 * RESERVE account (float account-to-account, branch → bank, expense sources) are blocked until a reserve allocation is
 * configured; reserve accounts are left out of the source dropdowns.
 *
 * The one way out is towards the Investment RESERVE A/C, where the owners approve: Bank → Send Reserve To Investment,
 * and the RESERVE row of Headquarters Transaction (user ruling 2026-09-22), which may name no other destination.
 */
class ReserveProtection
{
    public const MESSAGE = 'Interest reserve is held in the reserve account and cannot be moved until a reserve allocation is configured.';

    /**
     * @return list<Account>
     */
    public static function reserveAccounts(): array
    {
        return [Account::Reserve, Account::HqReserve];
    }

    public static function isReserve(Account|string|null $account): bool
    {
        $account = is_string($account) ? Account::tryFrom($account) : $account;

        return $account !== null && in_array($account, self::reserveAccounts(), true);
    }

    /**
     * @param  list<Account>  $accounts
     * @return list<Account>
     */
    public static function withoutReserve(array $accounts): array
    {
        return array_values(array_filter($accounts, fn (Account $account): bool => ! self::isReserve($account)));
    }

    /**
     * @throws ValidationException when the source is a reserve account
     */
    public static function assertNotReserveSource(Account|string|null $account, string $field): void
    {
        if (self::isReserve($account)) {
            throw ValidationException::withMessages([$field => self::MESSAGE]);
        }
    }
}
