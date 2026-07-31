<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Customers\Enums\DynamicFieldType;
use App\Domain\Customers\Exceptions\InvalidDynamicFormDataException;
use App\Models\CustomerCategory;

/**
 * Validates `customers.dynamic_form_data` against its category's
 * `dynamic_form_schema` — spec §2.4 ("validated against
 * category.dynamic_form_schema") and §15.1.
 *
 * This matters more than it looks. The category IS the KYC rule engine: a
 * Public Servant must supply a check number, a Boda Boda a motorcycle
 * registration. Because the schema is per-category and admin-editable, no
 * static Form Request can express it — the rules are data, so validation has
 * to be too.
 */
final class DynamicFormValidator
{
    /**
     * Validates and normalises the submitted values.
     *
     * Returns only keys the schema declares. Silently dropping unknown keys is
     * deliberate: the column is JSON, so anything accepted here is stored
     * verbatim and would become indistinguishable from real KYC data later.
     *
     * @param array<string, mixed> $data
     * @return array<string, string|int|float>
     *
     * @throws InvalidDynamicFormDataException
     */
    public function validate(CustomerCategory $category, array $data): array
    {
        $errors = [];
        $clean = [];

        foreach ($category->dynamic_form_schema as $field) {
            $key = (string) ($field['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $label = (string) ($field['label'] ?? $key);
            $required = (bool) ($field['required'] ?? false);
            $type = DynamicFieldType::tryFrom((string) ($field['type'] ?? 'text')) ?? DynamicFieldType::Text;

            $value = $data[$key] ?? null;
            $missing = $value === null || (is_string($value) && trim($value) === '');

            if ($missing) {
                if ($required) {
                    $errors["dynamicFormData.{$key}"] = ["{$label} is required."];
                }

                continue;
            }

            $result = $this->coerce($value, $type, $field);

            if ($result === null) {
                $errors["dynamicFormData.{$key}"] = [$this->messageFor($label, $type, $field)];

                continue;
            }

            $clean[$key] = $result;
        }

        if ($errors !== []) {
            throw new InvalidDynamicFormDataException($errors);
        }

        return $clean;
    }

    /**
     * No branch returns a bool: the frontend's field-type enum is
     * text/number/select/date/textarea, with no checkbox. Its
     * `dynamicFormData` record type permits booleans, but nothing can
     * currently produce one — adding a boolean field type would mean
     * widening this again, deliberately.
     *
     * @param array<string, mixed> $field
     * @return string|int|float|null null signals "invalid for this type"
     */
    private function coerce(mixed $value, DynamicFieldType $type, array $field): string|int|float|null
    {
        return match ($type) {
            DynamicFieldType::Number => is_numeric($value) ? $value + 0 : null,

            DynamicFieldType::Date => $this->isDate((string) $value) ? (string) $value : null,

            DynamicFieldType::Select => $this->isAllowedOption((string) $value, $field) ? (string) $value : null,

            DynamicFieldType::Text, DynamicFieldType::Textarea => is_scalar($value) ? (string) $value : null,
        };
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function isAllowedOption(string $value, array $field): bool
    {
        $options = $field['options'] ?? null;

        // A select with no declared options constrains nothing.
        if (! is_array($options) || $options === []) {
            return true;
        }

        return in_array($value, array_map(strval(...), $options), true);
    }

    /**
     * @param array<string, mixed> $field
     */
    private function messageFor(string $label, DynamicFieldType $type, array $field): string
    {
        return match ($type) {
            DynamicFieldType::Number => "{$label} must be a number.",
            DynamicFieldType::Date => "{$label} must be a valid date (YYYY-MM-DD).",
            DynamicFieldType::Select => sprintf(
                '%s must be one of: %s.',
                $label,
                implode(', ', array_map(strval(...), (array) ($field['options'] ?? []))),
            ),
            default => "{$label} is invalid.",
        };
    }
}
