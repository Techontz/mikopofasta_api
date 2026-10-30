<?php

namespace App\Services\Customers;

use RuntimeException;

/**
 * The registration may belong to an existing customer ({@see DuplicateCustomerCheck}): it is held for review, not created.
 */
class PossibleDuplicateCustomer extends RuntimeException
{
    /**
     * @param  list<array{customer_id: int, reasons: list<string>}>  $matches
     */
    public function __construct(public readonly array $matches)
    {
        parent::__construct('This registration may belong to an existing customer and was sent for verification.');
    }
}
