<?php

namespace App\Enums;

/**
 * How a loan is secured and collected, chosen per loan on the application form (any loan category, any customer type).
 * PhoneBond is eSIMU: a smartphone enrolled with Linda Device Lock secures the loan (the stored value stays phone_bond).
 */
enum LoanMethod: string
{
    case Normal = 'normal';
    case EMandate = 'e_mandate';
    case PhoneBond = 'phone_bond';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::EMandate => 'E-Mandate',
            self::PhoneBond => 'eSIMU',
        };
    }
}
