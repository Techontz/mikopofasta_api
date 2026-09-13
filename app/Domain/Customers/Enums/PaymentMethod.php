<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/**
 * Which kind of account the customer is paid into and repays from.
 *
 * WHY IT IS STORED RATHER THAN INFERRED. The registration form has always
 * asked this — a wallet or a bank account, never both — but only the ANSWERS
 * were kept, and the question itself was reconstructed afterwards by looking
 * at which columns turned out to be filled. That guess is wrong in both
 * directions: a customer who chose Bank and whose account number was later
 * cleared reads as a wallet customer, and a record carrying a stale wallet
 * number from an earlier edit reads as MNO no matter what the officer chose.
 * The choice is a fact the officer stated, so it is recorded as one.
 *
 * NULL IS A THIRD STATE and deliberately not a case here: it means no account
 * has been given at all, which is legitimate for an account type that does not
 * require one. A case called `None` would invite it being written where the
 * column should simply be empty.
 */
enum PaymentMethod: string
{
    case Mno = 'mno';
    case Bank = 'bank';
}
