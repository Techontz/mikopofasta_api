<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Customers\Enums\DynamicFieldType;
use App\Domain\Customers\Exceptions\InvalidDynamicFormDataException;
use App\Domain\Customers\Support\StructuredRegistrationField;
use App\Models\CustomerCategory;
use App\Support\MasterDataRegistry;

/**
 * Validates a registration against its customer type's configured form —
 * spec §2.4 ("validated against category.dynamic_form_schema") and §15.1.
 *
 * This matters more than it looks. The customer type IS the KYC rule engine: a
 * public servant must name the sector they serve, a private-sector employee
 * must say what they do. Because the schema is per-type and admin-editable, no
 * static Form Request can express it — the rules are data, so the validation
 * has to be too.
 *
 * IT VALIDATES BOTH HALVES OF THE ANSWER. A configured field either names a
 * real customer column (see StructuredRegistrationField) or it does not. The
 * first kind arrives in the registration payload under its own key and is
 * written to that column; the second lands in `dynamic_form_data`. Before this,
 * only the second kind was checked, which meant a customer type could mark
 * "Basic Salary" required and the server would accept a registration without
 * one — the rule was configured, displayed by the form, and enforced nowhere.
 * So the payload is passed in and both are judged by the same pass, and errors
 * come back keyed the way the client names each field: `basicSalary` for a
 * structured one, `dynamicFormData.land_size` for the rest.
 *
 * CONDITIONS ARE DATA TOO. `requiredWhen` on a field says which OTHER field's
 * answer makes this one mandatory — the contract expiry date that a temporary
 * contract demands and a permanent one does not. It compares against the
 * referenced option's CODE, never its name or its id, so an administrator may
 * rename or translate "Kwa Muda" without breaking the rule, and the same
 * comparison works in a database where the row has a different id.
 */
final class DynamicFormValidator
{
    /**
     * Validates the submitted registration and returns the JSON half of it.
     *
     * The return value holds only the non-structured keys the schema declares.
     * Silently dropping unknown keys is deliberate: the column is JSON, so
     * anything accepted here is stored verbatim and would become
     * indistinguishable from real KYC data later. Structured fields are not
     * returned at all — they are already in the payload, and the registration
     * action writes them to their own columns.
     *
     * @param array<string, mixed> $data the submitted `dynamicFormData`
     * @param array<string, mixed> $payload the whole registration payload, for
     *                                      fields that name a real column
     * @return array<string, string|int|float|bool>
     *
     * @throws InvalidDynamicFormDataException
     */
    public function validate(CustomerCategory $category, array $data, array $payload = []): array
    {
        $errors = [];
        $clean = [];

        $schema = $category->dynamic_form_schema;

        foreach ($schema as $field) {
            $key = (string) ($field['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $label = (string) ($field['label'] ?? $key);
            $type = DynamicFieldType::tryFrom((string) ($field['type'] ?? 'text')) ?? DynamicFieldType::Text;

            $structuredKey = StructuredRegistrationField::target($field);
            $errorKey = $structuredKey ?? "dynamicFormData.{$key}";

            $value = $structuredKey === null ? ($data[$key] ?? null) : ($payload[$structuredKey] ?? null);

            $required = (bool) ($field['required'] ?? false)
                || $this->conditionHolds($field, $schema, $data, $payload);

            if ($this->isBlank($value)) {
                if ($required) {
                    $errors[$errorKey] = ["{$label} is required."];
                }

                continue;
            }

            $result = $this->coerce($value, $type, $field, $schema, $data, $payload);

            if ($result === null) {
                $errors[$errorKey] = [$this->messageFor($label, $type, $field)];

                continue;
            }

            /* Structured answers belong to their columns, and the action writes
               them from the payload it was given. Returning them here too would
               file a second copy in the JSON column and leave the two free to
               disagree about the same fact. */
            if ($structuredKey === null) {
                $clean[$key] = $result;
            }
        }

        if ($errors !== []) {
            throw new InvalidDynamicFormDataException($errors);
        }

        return $clean;
    }

    private function isBlank(mixed $value): bool
    {
        /* `false` is an answer to a yes/no question, and `0` is an answer to
           "how many dependents?". Neither is a missing value, which is why
           this is not `empty()`. */
        return $value === null || (is_string($value) && trim($value) === '') || $value === [];
    }

    /**
     * Whether this field's `requiredWhen` condition is met.
     *
     *     requiredWhen: { field: "contract_type_id", equals: ["TEMPORARY"] }
     *
     * A missing or malformed condition holds nothing — a field with a broken
     * rule falls back to its own `required` flag rather than becoming
     * mandatory for everybody, which is the safer of the two failures.
     *
     * @param array<string, mixed> $field
     * @param list<array<string, mixed>> $schema
     * @param array<string, mixed> $data
     * @param array<string, mixed> $payload
     */
    private function conditionHolds(array $field, array $schema, array $data, array $payload): bool
    {
        $condition = $field['requiredWhen'] ?? null;

        if (! is_array($condition)) {
            return false;
        }

        $on = (string) ($condition['field'] ?? '');
        $expected = $condition['equals'] ?? null;

        if ($on === '' || ! is_array($expected) || $expected === []) {
            return false;
        }

        $structuredKey = $this->targetOf($on, $schema);
        $value = $structuredKey === null ? ($data[$on] ?? null) : ($payload[$structuredKey] ?? null);

        if ($this->isBlank($value)) {
            return false;
        }

        $expected = array_map(strval(...), $expected);

        /* The raw answer first — that is what a static-option select holds —
           then the CODE of the row it names, which is what a data-source
           select holds and what the condition is written against. */
        if (in_array((string) $value, $expected, true)) {
            return true;
        }

        $code = $this->codeOf($on, $schema, (string) $value);

        return $code !== null && in_array($code, $expected, true);
    }

    /**
     * Where another field files its answer, found by that field's key.
     *
     * @param list<array<string, mixed>> $schema
     */
    private function targetOf(string $key, array $schema): ?string
    {
        foreach ($schema as $field) {
            if ((string) ($field['key'] ?? '') === $key) {
                return StructuredRegistrationField::target($field);
            }
        }

        return null;
    }

    /**
     * The stable code of the option a data-source field points at.
     *
     * @param list<array<string, mixed>> $schema
     */
    private function codeOf(string $key, array $schema, string $id): ?string
    {
        foreach ($schema as $field) {
            if ((string) ($field['key'] ?? '') !== $key) {
                continue;
            }

            $source = (string) ($field['dataSource'] ?? '');
            $model = $source === '' ? null : MasterDataRegistry::model($source);

            if ($model === null) {
                return null;
            }

            return $model::query()->whereKey($id)->value('code');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $field
     * @param list<array<string, mixed>> $schema
     * @param array<string, mixed> $data
     * @param array<string, mixed> $payload
     * @return string|int|float|bool|null null signals "invalid for this type"
     */
    private function coerce(
        mixed $value,
        DynamicFieldType $type,
        array $field,
        array $schema,
        array $data,
        array $payload,
    ): string|int|float|bool|null {
        return match ($type) {
            /* Currency is a number that is SHOWN with a shilling prefix and
               thousands grouping. Nothing about the value differs, and
               validating it differently would be inventing a distinction the
               business does not make. */
            DynamicFieldType::Number, DynamicFieldType::Currency => is_numeric($value) ? $value + 0 : null,

            DynamicFieldType::Boolean => $this->toBool($value),

            DynamicFieldType::Date => $this->isDate((string) $value) ? (string) $value : null,

            DynamicFieldType::Select => $this->isAllowedOption($value, $field, $schema, $data, $payload)
                ? (string) $value
                : null,

            DynamicFieldType::Text, DynamicFieldType::Textarea => is_scalar($value) ? (string) $value : null,
        };
    }

    private function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'yes', 'ndiyo' => true,
            '0', 'false', 'no', 'hapana' => false,
            default => null,
        };
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false;
    }

    /**
     * Whether a select's answer is one the field actually offers.
     *
     * Three cases, in order:
     *
     *   A DATA SOURCE — the answer is the id of a row in an admin-managed list,
     *   and it must exist and still be selectable. When the field also declares
     *   `dependsOn`, the row must belong to the parent that was chosen: this is
     *   what stops a cadre from one ministry being filed against another, and
     *   it is checked here rather than trusted from the browser.
     *
     *   A STATIC LIST — the answer must be one of the declared strings.
     *
     *   NEITHER — the field constrains nothing, and anything is accepted.
     *
     * @param array<string, mixed> $field
     * @param list<array<string, mixed>> $schema
     * @param array<string, mixed> $data
     * @param array<string, mixed> $payload
     */
    private function isAllowedOption(mixed $value, array $field, array $schema, array $data, array $payload): bool
    {
        $source = (string) ($field['dataSource'] ?? '');

        if ($source !== '') {
            $model = MasterDataRegistry::model($source);

            /* A source the application no longer serves constrains nothing,
               rather than failing every registration under this category. */
            if ($model === null) {
                return true;
            }

            $query = $model::query()->whereKey($value);

            $parentColumn = MasterDataRegistry::parentColumn($source);
            $dependsOn = (string) ($field['dependsOn'] ?? '');

            if ($parentColumn !== null && $dependsOn !== '') {
                $structuredKey = $this->targetOf($dependsOn, $schema);
                $parent = $structuredKey === null ? ($data[$dependsOn] ?? null) : ($payload[$structuredKey] ?? null);

                /* No parent chosen: the child cannot be judged, and the parent's
                   own `required` rule is what should complain. */
                if ($this->isBlank($parent)) {
                    return false;
                }

                $query->where($parentColumn, $parent);
            }

            return $query->exists();
        }

        $options = $field['options'] ?? null;

        if (! is_array($options) || $options === []) {
            return true;
        }

        return in_array((string) $value, array_map(strval(...), $options), true);
    }

    /**
     * @param array<string, mixed> $field
     */
    private function messageFor(string $label, DynamicFieldType $type, array $field): string
    {
        $options = (array) ($field['options'] ?? []);

        return match ($type) {
            DynamicFieldType::Number => "{$label} must be a number.",
            DynamicFieldType::Currency => "{$label} must be an amount.",
            DynamicFieldType::Boolean => "{$label} must be yes or no.",
            DynamicFieldType::Date => "{$label} must be a valid date (YYYY-MM-DD).",
            DynamicFieldType::Select => $options === []
                /* A data-source select cannot list its options in an error —
                   there may be hundreds, and they change. It names the reason
                   instead, which is the part the officer can act on. */
                ? "{$label} is not a valid choice. Choose one of the options offered."
                : sprintf('%s must be one of: %s.', $label, implode(', ', array_map(strval(...), $options))),
            default => "{$label} is invalid.",
        };
    }
}
