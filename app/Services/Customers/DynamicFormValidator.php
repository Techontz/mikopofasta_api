<?php

namespace App\Services\Customers;

use App\Models\CustomerCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Validates a registration's customer-type answers against the type's `dynamic_form_schema`
 * (CUSTOMER_MODULE_IMPLEMENTATION.md §5.3, CUSTOMER_TYPE_REQUIREMENTS.md §9–10).
 */
class DynamicFormValidator
{
    /**
     * Customer columns an answer may be stored in through `storesIn` (camelCase payload name => column).
     *
     * @var array<string, string>
     */
    public const STORES_IN_COLUMNS = [
        'placeOfEmployment' => 'place_of_employment',
        'checkNumber' => 'check_number',
        'basicSalary' => 'basic_salary',
        'takeHome' => 'take_home',
        'monthlyIncome' => 'monthly_income',
        'retirementDate' => 'retirement_date',
        'businessName' => 'business_name',
        'businessType' => 'business_type',
        'businessAddress' => 'business_address',
        'tinNumber' => 'tin_number',
        'occupation' => 'occupation',
        'employer' => 'employer',
        'employerId' => 'employer_id',
        'department' => 'department',
        'councilNumber' => 'council_number',
        'employmentType' => 'employment_type',
        'workType' => 'work_type',
        'sectorId' => 'sector_id',
        'sectorCategoryId' => 'sector_category_id',
        'contractTypeId' => 'contract_type_id',
        'contractExpiryDate' => 'contract_expiry_date',
        'dependentsCount' => 'dependents',
        'alternativePhone' => 'alternative_phone',
        'email' => 'email',
        'nationality' => 'nationality',
        'maritalStatusId' => 'marital_status_id',
        'residenceType' => 'residence_type',
        'bankId' => 'bank_id',
        'bankBranch' => 'bank_branch',
        'mobileMoneyProviderId' => 'mobile_money_provider_id',
        'walletNumber' => 'wallet_number',
    ];

    /**
     * @var list<string>
     */
    private const TRUE_VALUES = ['1', 'true', 'yes', 'ndiyo'];

    /**
     * @var list<string>
     */
    private const FALSE_VALUES = ['0', 'false', 'no', 'hapana'];

    public function __construct(private MasterDataRegistry $registry) {}

    /**
     * Every answer error at once, keyed by the `storesIn` payload name or `dynamicFormData.<key>`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public function errors(?CustomerCategory $category, array $payload): array
    {
        $schema = $this->schema($category);
        $errors = [];

        foreach ($schema as $field) {
            $value = $this->answer($field, $payload);
            $label = (string) ($field['label'] ?? $field['key']);
            $errorKey = $this->errorKey($field);

            if ($this->isBlank($value)) {
                if ($this->isRequired($field, $schema, $payload)) {
                    $errors[$errorKey] = "{$label} is required.";
                }

                continue;
            }

            $message = $this->formatError($field, $value, $label, $schema, $payload);
            if ($message !== null) {
                $errors[$errorKey] = $message;
            }
        }

        return $errors;
    }

    /**
     * The answers stored in `dynamic_form_data`: schema keys without `storesIn`, normalised; unknown keys dropped.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function answers(?CustomerCategory $category, array $payload): array
    {
        $answers = [];

        foreach ($this->schema($category) as $field) {
            if ($this->storesInColumn($field) !== null) {
                continue;
            }

            $value = $this->answer($field, $payload);
            if (! $this->isBlank($value)) {
                $answers[$field['key']] = $this->normalise($field, $value);
            }
        }

        return $answers;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function schema(?CustomerCategory $category): array
    {
        return collect($category?->dynamic_form_schema ?? [])
            ->filter(fn ($field): bool => is_array($field) && filled($field['key'] ?? null))
            ->values()
            ->all();
    }

    /**
     * The customer column (snake_case) a field writes to, or null when it is stored in `dynamic_form_data`.
     *
     * @param  array<string, mixed>  $field
     */
    public function storesInColumn(array $field): ?string
    {
        $storesIn = $field['storesIn'] ?? null;

        return is_string($storesIn) ? (self::STORES_IN_COLUMNS[$storesIn] ?? null) : null;
    }

    public function isBlank(mixed $value): bool
    {
        return $value === null
            || (is_string($value) && trim($value) === '')
            || (is_array($value) && $value === []);
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $payload
     */
    private function answer(array $field, array $payload): mixed
    {
        if ($this->storesInColumn($field) !== null) {
            return $payload[$field['storesIn']] ?? null;
        }

        $answers = $payload['dynamicFormData'] ?? [];

        return is_array($answers) ? ($answers[$field['key']] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function errorKey(array $field): string
    {
        return $this->storesInColumn($field) !== null ? (string) $field['storesIn'] : 'dynamicFormData.'.$field['key'];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<array<string, mixed>>  $schema
     * @param  array<string, mixed>  $payload
     */
    private function isRequired(array $field, array $schema, array $payload): bool
    {
        if (($field['required'] ?? false) === true) {
            return true;
        }

        $condition = $field['requiredWhen'] ?? null;
        if (! is_array($condition) || ! is_string($condition['field'] ?? null)) {
            return false;
        }

        $expected = array_map(fn ($value): string => (string) $value, Arr::wrap($condition['equals'] ?? []));
        $referenced = collect($schema)->firstWhere('key', $condition['field']);
        $value = $referenced !== null
            ? $this->answer($referenced, $payload)
            : (Arr::get($payload, 'dynamicFormData.'.$condition['field']) ?? $payload[str($condition['field'])->camel()->toString()] ?? null);

        if ($this->isBlank($value) || ! is_scalar($value)) {
            return false;
        }

        $candidates = [(string) $value];
        $dataSource = $referenced['dataSource'] ?? null;
        if (is_string($dataSource) && ($row = $this->registry->find($dataSource, $value)) !== null) {
            $candidates[] = (string) $row->code;
        }

        return array_intersect($candidates, $expected) !== [];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<array<string, mixed>>  $schema
     * @param  array<string, mixed>  $payload
     */
    private function formatError(array $field, mixed $value, string $label, array $schema, array $payload): ?string
    {
        $type = $field['type'] ?? 'text';

        return match (true) {
            in_array($type, ['number', 'currency'], true) => is_numeric($value) ? null : "{$label} must be a number.",
            $type === 'date' => $this->isDate($value) ? null : "{$label} must be a date in the format YYYY-MM-DD.",
            $type === 'boolean' => $this->booleanValue($value) !== null ? null : "{$label} must be yes or no.",
            $type === 'select' && is_string($field['dataSource'] ?? null) => $this->dataSourceError($field, $value, $label, $schema, $payload),
            $type === 'select' && is_array($field['options'] ?? null) => is_scalar($value) && in_array((string) $value, array_map('strval', $field['options']), true) ? null : "{$label} must be one of the listed options.",
            default => is_scalar($value) ? null : "{$label} must be text.",
        };
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<array<string, mixed>>  $schema
     * @param  array<string, mixed>  $payload
     */
    private function dataSourceError(array $field, mixed $value, string $label, array $schema, array $payload): ?string
    {
        $slug = $field['dataSource'];
        $row = $this->registry->find($slug, $value);
        if ($row === null) {
            return "The selected {$label} does not exist.";
        }

        $parentKey = $field['dependsOn'] ?? null;
        $parentColumn = $this->registry->parentColumn($slug);
        if (! is_string($parentKey) || $parentColumn === null) {
            return null;
        }

        $parentField = collect($schema)->firstWhere('key', $parentKey);
        $parentValue = $parentField !== null ? $this->answer($parentField, $payload) : Arr::get($payload, 'dynamicFormData.'.$parentKey);
        $parentLabel = (string) ($parentField['label'] ?? $parentKey);

        if ($this->isBlank($parentValue) || ! is_scalar($parentValue) || (string) $row->{$parentColumn} !== trim((string) $parentValue)) {
            return "The selected {$label} does not belong to the chosen {$parentLabel}.";
        }

        return null;
    }

    private function isDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)?->format('Y-m-d') === $value;
        } catch (Throwable) {
            return false;
        }
    }

    private function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $text = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return match (true) {
            in_array($text, self::TRUE_VALUES, true) => true,
            in_array($text, self::FALSE_VALUES, true) => false,
            default => null,
        };
    }

    /**
     * Stored shape: master-data ids and numbers as numbers, booleans as booleans, everything else as trimmed text.
     *
     * @param  array<string, mixed>  $field
     */
    private function normalise(array $field, mixed $value): mixed
    {
        $type = $field['type'] ?? 'text';

        return match (true) {
            in_array($type, ['number', 'currency'], true) => $value + 0,
            $type === 'boolean' => $this->booleanValue($value),
            $type === 'select' && is_string($field['dataSource'] ?? null) => (int) $value,
            default => is_string($value) ? trim($value) : $value,
        };
    }
}
