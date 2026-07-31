<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/**
 * Field types a customer category's dynamic KYC form may declare —
 * the frontend's DynamicFormFieldSchema.
 */
enum DynamicFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Select = 'select';
    case Date = 'date';
    case Textarea = 'textarea';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
