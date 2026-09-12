<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Enums;

/**
 * What kind of money account this is.
 *
 * A company's financial account is a CHANNEL — somewhere money is held and
 * through which it moves. It is not an accounting category: "NMB" says where
 * the money is, "Principal" says what it represents, and the two are recorded
 * on different axes. The chart of accounts holds the second; this holds the
 * first.
 *
 * ## Why Cash is not a case here
 *
 * Cash is a payment METHOD, not a registered account. There is no bank to name
 * and no wallet number to reconcile against, and inventing a "Cash Bank" row to
 * carry it would put a fictional external account in a table whose whole
 * purpose is reconciling against real ones.
 *
 * The ledger already has somewhere for it: the teller cash accounts (1500-x in
 * the chart). A cash payment posts there, exactly as it does today. What
 * changes is only that a bank or wallet payment now names which registered
 * channel received it.
 */
enum AccountChannelType: string
{
    /** A bank account. `bank_id` names the institution. */
    case Bank = 'bank';

    /** A mobile money wallet. `mobile_money_provider_id` names the operator. */
    case Mno = 'mno';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Bank',
            self::Mno => 'Mobile Money',
        };
    }
}
