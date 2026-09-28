<?php

namespace App\Services\Assets;

use App\Enums\Account;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Reads the asset type configuration (config/assets.php): type-specific fields and their validation rules, the
 * fixed-asset ledger account of each type, suggested documents and the option lists the web form renders from.
 */
class AssetTypes
{
    /**
     * @return array<string, array{label: string, account: string, fixed_quantity: bool, condition: bool, location_required: bool, fields: list<array<string, mixed>>, documents: list<array{key: string, label: string, required: bool}>}>
     */
    public function all(): array
    {
        return config('assets.types');
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array{label: string, account: string, fixed_quantity: bool, condition: bool, location_required: bool, fields: list<array<string, mixed>>, documents: list<array{key: string, label: string, required: bool}>}
     */
    public function get(string $type): array
    {
        return $this->all()[$type] ?? throw new InvalidArgumentException("Unknown asset type [{$type}].");
    }

    public function exists(?string $type): bool
    {
        return $type !== null && array_key_exists($type, $this->all());
    }

    public function label(string $type): string
    {
        return $this->all()[$type]['label'] ?? $type;
    }

    /**
     * The fixed-asset account a contribution of this type is debited to. Only fixed-asset accounts are allowed, so an
     * asset contribution can never move cash or bank.
     */
    public function account(string $type): Account
    {
        $account = Account::tryFrom($this->get($type)['account']);
        if ($account === null || ! in_array($account, Account::fixedAssets(), true)) {
            throw new InvalidArgumentException("Asset type [{$type}] must map to a fixed-asset account.");
        }

        return $account;
    }

    /**
     * Validation rules for the `specifications.*` fields of a type (unknown keys are dropped by {@see specifications()}).
     *
     * @return array<string, list<mixed>>
     */
    public function specificationRules(string $type): array
    {
        $rules = [];
        foreach ($this->get($type)['fields'] as $field) {
            $fieldRules = [($field['required'] ?? false) ? 'required' : 'nullable'];
            $fieldRules = [...$fieldRules, ...match ($field['type']) {
                'integer' => ['integer'],
                'number' => ['numeric'],
                'select' => [Rule::in(array_keys($field['options'] ?? []))],
                'textarea' => ['string', 'max:2000'],
                default => ['string', 'max:191'],
            }];
            if (isset($field['min'])) {
                $fieldRules[] = 'min:'.$field['min'];
            }
            if (isset($field['max'])) {
                $fieldRules[] = 'max:'.$field['max'];
            }
            $rules['specifications.'.$field['key']] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Attribute names ("specifications.chassis_number" → "chassis number") for validation messages.
     *
     * @return array<string, string>
     */
    public function specificationAttributes(string $type): array
    {
        $attributes = [];
        foreach ($this->get($type)['fields'] as $field) {
            $attributes['specifications.'.$field['key']] = mb_strtolower($field['label']);
        }

        return $attributes;
    }

    /**
     * Only the configured fields of the type, trimmed, without empty values.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|int|float>
     */
    public function specifications(string $type, array $input): array
    {
        $values = [];
        foreach ($this->get($type)['fields'] as $field) {
            $value = $input[$field['key']] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }
            $values[$field['key']] = match ($field['type']) {
                'integer' => (int) $value,
                'number' => (float) $value,
                default => trim((string) $value),
            };
        }

        return $values;
    }

    /**
     * Identifier fields present on an asset (serial, registration, chassis, IMEI, plot/title numbers) as label => value.
     *
     * @param  array<string, mixed>|null  $specifications
     * @return array<string, string>
     */
    public function identifiers(string $type, ?array $specifications): array
    {
        if (! $this->exists($type)) {
            return [];
        }

        $identifiers = [];
        foreach ($this->get($type)['fields'] as $field) {
            if (($field['identifier'] ?? false) && filled($specifications[$field['key']] ?? null)) {
                $identifiers[$field['label']] = (string) $specifications[$field['key']];
            }
        }

        return $identifiers;
    }

    /**
     * @return list<string>
     */
    public function documentTypes(string $type): array
    {
        return [...array_column($this->get($type)['documents'], 'key'), 'other'];
    }

    /**
     * Configuration served to the web form.
     *
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        $options = fn (array $map): array => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($map), $map);

        return [
            'types' => array_map(fn (string $key, array $type): array => [
                'value' => $key,
                'label' => $type['label'],
                'account' => $type['account'],
                'account_label' => $this->account($key)->label(),
                'fixed_quantity' => (bool) $type['fixed_quantity'],
                'condition' => (bool) $type['condition'],
                'location_required' => (bool) $type['location_required'],
                'fields' => array_map(fn (array $field): array => [
                    'key' => $field['key'],
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'required' => (bool) ($field['required'] ?? false),
                    'identifier' => (bool) ($field['identifier'] ?? false),
                    'min' => $field['min'] ?? null,
                    'max' => $field['max'] ?? null,
                    'options' => isset($field['options']) ? $options($field['options']) : null,
                ], $type['fields']),
                'documents' => [...$type['documents'], ['key' => 'other', 'label' => 'Other', 'required' => false]],
            ], array_keys($this->all()), $this->all()),
            'conditions' => $options(config('assets.conditions')),
            'valuation_methods' => $options(config('assets.valuation_methods')),
            'statuses' => $options(config('assets.statuses')),
            'document_mimes' => config('assets.document_mimes'),
            'document_max_kb' => config('assets.document_max_kb'),
        ];
    }
}
