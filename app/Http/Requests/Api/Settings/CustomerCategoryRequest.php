<?php

namespace App\Http\Requests\Api\Settings;

use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Services\Customers\MasterDataRegistry;
use App\Services\Customers\StepTwoFields;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / update a customer type (CUSTOMER_MODULE_IMPLEMENTATION.md §3.3). Super Admin only; authorization
 * runs before validation. Customer types hold no loan configuration: loan limits and loan products are refused here
 * (they belong to the loan categories of the customer type).
 */
class CustomerCategoryRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    public const RISK_TIERS = ['low', 'medium', 'high'];

    /**
     * @var list<string>
     */
    public const SECTORS = ['employment', 'business', 'other'];

    /**
     * @var list<string>
     */
    public const FIELD_TYPES = ['text', 'textarea', 'number', 'currency', 'date', 'select', 'boolean'];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof Employee && $user->role?->key === 'super_admin';
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Only the Super Administrator can create, edit or delete customer types.');
    }

    protected function prepareForValidation(): void
    {
        foreach (['requiredDocuments', 'optionalDocuments', 'omittedStandardFields'] as $list) {
            if (is_array($this->input($list))) {
                $this->merge([$list => array_values(array_filter(
                    array_map(fn ($value): string => trim((string) $value), $this->input($list)),
                    fn (string $value): bool => $value !== '',
                ))]);
            }
        }

        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var CustomerCategory|null $category */
        $category = $this->route('customer_category');
        $companyId = $this->user()->company_id;
        $standardKeys = array_values(array_unique(array_column([...StepTwoFields::EMPLOYMENT, ...StepTwoFields::BUSINESS, ...StepTwoFields::OTHER], 'key')));
        $dataSources = [...app(MasterDataRegistry::class)->slugs(), 'sectors', 'sector-categories', 'employers', 'contract-types'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9_]+$/', Rule::unique('customer_categories', 'code')->where('company_id', $companyId)->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'formTitle' => ['nullable', 'string', 'max:255'],
            'isActive' => ['required', 'boolean'],
            'sortOrder' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'riskTier' => ['nullable', Rule::in(self::RISK_TIERS)],
            'sector' => ['required', Rule::in(self::SECTORS)],
            'requiresSector' => ['boolean'],
            'requiresEmployer' => ['boolean'],
            'requiresContract' => ['boolean'],
            'requiresSalary' => ['boolean'],
            'requiresExtraApproval' => ['boolean'],
            'requiredDocuments' => ['present', 'array'],
            'requiredDocuments.*' => ['string', 'max:60', 'distinct'],
            'optionalDocuments' => ['present', 'array'],
            'optionalDocuments.*' => ['string', 'max:60', 'distinct'],
            'omittedStandardFields' => ['present', 'array'],
            'omittedStandardFields.*' => ['string', 'distinct', Rule::in($standardKeys)],
            'dynamicFormSchema' => ['present', 'array', 'list'],
            'dynamicFormSchema.*' => ['array'],
            'dynamicFormSchema.*.key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'dynamicFormSchema.*.label' => ['required', 'string', 'max:255'],
            'dynamicFormSchema.*.type' => ['required', Rule::in(self::FIELD_TYPES)],
            'dynamicFormSchema.*.required' => ['sometimes', 'boolean'],
            'dynamicFormSchema.*.options' => ['sometimes', 'array', 'min:1'],
            'dynamicFormSchema.*.options.*' => ['string', 'max:255', 'distinct'],
            'dynamicFormSchema.*.dataSource' => ['sometimes', 'string', Rule::in($dataSources)],
            'dynamicFormSchema.*.dependsOn' => ['sometimes', 'nullable', 'string'],
            'dynamicFormSchema.*.storesIn' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^[a-z][A-Za-z0-9]*$/'],
            'dynamicFormSchema.*.requiredWhen' => ['sometimes', 'nullable', 'array'],
            'dynamicFormSchema.*.requiredWhen.field' => ['required_with:dynamicFormSchema.*.requiredWhen', 'string'],
            'dynamicFormSchema.*.requiredWhen.equals' => ['required_with:dynamicFormSchema.*.requiredWhen', 'array'],
            'dynamicFormSchema.*.fullWidth' => ['sometimes', 'boolean'],
            'dynamicFormSchema.*.placeholder' => ['sometimes', 'nullable', 'string', 'max:255'],
            'dynamicFormSchema.*.helpText' => ['sometimes', 'nullable', 'string', 'max:500'],
            'minLoanAmount' => ['prohibited'],
            'maxLoanAmount' => ['prohibited'],
            'loanCategoryIds' => ['prohibited'],
        ];
    }

    /**
     * Cross-field schema checks: a select needs options or a data source, dependsOn names an earlier field.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $keys = [];
            foreach ((array) $this->input('dynamicFormSchema', []) as $index => $field) {
                if (! is_array($field)) {
                    continue;
                }
                if (($field['type'] ?? null) === 'select' && empty($field['options']) && empty($field['dataSource'])) {
                    $validator->errors()->add("dynamicFormSchema.{$index}.options", 'A select field needs fixed options or a data source.');
                }
                if (! empty($field['dependsOn']) && ! in_array($field['dependsOn'], $keys, true)) {
                    $validator->errors()->add("dynamicFormSchema.{$index}.dependsOn", 'A field can only depend on a field listed before it.');
                }
                if (isset($field['key']) && is_string($field['key'])) {
                    $keys[] = $field['key'];
                }
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'dynamicFormSchema.*.key' => 'field key',
            'dynamicFormSchema.*.label' => 'field label',
            'dynamicFormSchema.*.type' => 'field type',
            'requiredDocuments.*' => 'required document',
            'optionalDocuments.*' => 'optional document',
            'omittedStandardFields.*' => 'omitted standard field',
        ];
    }

    /**
     * Column values for customer_categories.
     *
     * @return array<string, mixed>
     */
    public function categoryData(): array
    {
        $data = [
            'name' => $this->string('name')->trim()->toString(),
            'code' => $this->string('code')->toString(),
            'description' => $this->filled('description') ? $this->string('description')->trim()->toString() : null,
            'form_title' => $this->filled('formTitle') ? $this->string('formTitle')->trim()->toString() : null,
            'is_active' => $this->boolean('isActive'),
            'sort_order' => $this->integer('sortOrder'),
            'risk_tier' => $this->input('riskTier'),
            'sector' => $this->string('sector')->toString(),
            'requires_sector' => $this->boolean('requiresSector'),
            'requires_employer' => $this->boolean('requiresEmployer'),
            'requires_contract' => $this->boolean('requiresContract'),
            'requires_salary' => $this->boolean('requiresSalary'),
            'requires_extra_approval' => $this->boolean('requiresExtraApproval'),
            'required_documents' => $this->input('requiredDocuments', []),
            'optional_documents' => $this->input('optionalDocuments', []),
            'omitted_standard_fields' => $this->input('omittedStandardFields', []),
            'dynamic_form_schema' => $this->input('dynamicFormSchema', []),
        ];

        if ($this->has('riskTier') && in_array($this->input('riskTier'), self::RISK_TIERS, true)) {
            $data['risk_level'] = $this->input('riskTier');
        }

        return $data;
    }
}
