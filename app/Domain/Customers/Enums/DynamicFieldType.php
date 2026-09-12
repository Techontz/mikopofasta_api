<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/**
 * Field types a customer category's registration form may declare —
 * the frontend's DynamicFormFieldSchema.
 *
 * CURRENCY IS NOT NUMBER. Both hold a number, and a form that showed them
 * identically would be asking an officer to read "1200000" and decide for
 * themselves whether it is shillings. The type carries the presentation — a
 * currency prefix and thousands grouping — and the validator treats the value
 * exactly as it treats a number, which is the point: nothing about money is
 * decided differently, only shown differently.
 *
 * BOOLEAN was reachable in the frontend's stored-value type from the start and
 * had no way to be produced, because no field type yielded one. A yes/no
 * question is the most ordinary thing an institution asks and it needed a
 * checkbox, not a select of the strings "Yes" and "No".
 */
enum DynamicFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Currency = 'currency';
    case Select = 'select';
    case Date = 'date';
    case Textarea = 'textarea';
    case Boolean = 'boolean';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
