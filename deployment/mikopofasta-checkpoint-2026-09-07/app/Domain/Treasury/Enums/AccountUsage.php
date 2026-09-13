<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Enums;

/**
 * Which direction(s) an account may be selected for.
 *
 * ## The money model this serves
 *
 *   MONEY IN     Collection (from customers)   Capital (from the owners)
 *   MONEY OUT    Disbursement (to customers)   Expenses (operational)
 *
 * All four move through the same registered accounts. This says which of them
 * a given account may appear in a selector for.
 *
 * ## Two directions, not four purposes — and that is deliberate
 *
 * It would be possible to enumerate Collection / Capital / Disbursement /
 * Expenses here and let an account opt into each. That would be wrong, and the
 * reason is worth stating because it is easy to get backwards.
 *
 * Collection and Capital are both money ARRIVING; Disbursement and Expenses are
 * both money LEAVING. What an account can physically do is receive, send, or
 * both — a bank account that can take a customer repayment can equally take a
 * capital injection, because the bank does not know the difference. The
 * difference is what the TRANSACTION means, and that is recorded where it
 * belongs: on the transaction, against the chart of accounts.
 *
 * So an account restricted to `Collection` is available wherever money comes
 * in, and one restricted to `Disbursement` wherever money goes out. Splitting
 * further would ask the officer to answer a question the bank cannot.
 *
 * ## And no two-account workaround
 *
 * `Both` exists so one physical account is registered once. A company using NMB
 * 123456789 for money in and out registers it ONCE with `Both` — not as an "NMB
 * Collection Account" and an "NMB Disbursement Account", which would be two
 * rows for one real account, two balances to reconcile against one statement,
 * and a total that double-counts.
 */
enum AccountUsage: string
{
    /** Money in: customer collections and capital injections. */
    case Collection = 'collection';

    /** Money out: loan disbursements and operational expenses. */
    case Disbursement = 'disbursement';

    /** Both directions. One physical account, used for everything. */
    case Both = 'both';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** Whether this account may receive money — collections and capital. */
    public function acceptsInflow(): bool
    {
        return $this === self::Collection || $this === self::Both;
    }

    /** Whether this account may send money — disbursements and expenses. */
    public function acceptsOutflow(): bool
    {
        return $this === self::Disbursement || $this === self::Both;
    }

    public function label(): string
    {
        return match ($this) {
            self::Collection => 'Collection',
            self::Disbursement => 'Disbursement',
            self::Both => 'Both',
        };
    }

    /** Shown under the control, so the officer is not guessing. */
    public function helper(): string
    {
        return match ($this) {
            self::Collection => 'Money received by the company',
            self::Disbursement => 'Money paid out by the company',
            self::Both => 'Account can receive and send money',
        };
    }
}
