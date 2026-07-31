<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Domain\Customers\Enums\CategorySector;
use App\Domain\Customers\Enums\DynamicFieldType;
use App\Domain\Customers\Enums\RiskTier;
use App\Models\CustomerCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's CategoryInputSchema
 * (features/admin/customer-categories/actions.ts), which picks name, code,
 * riskTier, sector, requiredDocuments, dynamicFormSchema and
 * requiresExtraApproval.
 *
 * The nested `dynamicFormSchema.*` rules validate the SHAPE of the field
 * definitions. What customers then submit against that schema is validated at
 * registration time by DynamicFormValidator — two different jobs.
 */
final class CustomerCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->route('category');
        $id = $category instanceof CustomerCategory ? $category->getKey() : null;

        return [
            'name' => ['required', 'string', 'min:2', 'max:120', Rule::unique('customer_categories', 'name')->ignore($id)->whereNull('deleted_at')],
            'code' => ['required', 'string', 'min:2', 'max:40', Rule::unique('customer_categories', 'code')->ignore($id)->whereNull('deleted_at')],
            'riskTier' => ['required', 'string', Rule::in(RiskTier::values())],
            'sector' => ['required', 'string', Rule::in(CategorySector::values())],

            'requiredDocuments' => ['present', 'array'],
            'requiredDocuments.*' => ['string', 'max:60'],

            'dynamicFormSchema' => ['present', 'array'],
            'dynamicFormSchema.*.key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'dynamicFormSchema.*.label' => ['required', 'string', 'max:150'],
            'dynamicFormSchema.*.type' => ['required', 'string', Rule::in(DynamicFieldType::values())],
            'dynamicFormSchema.*.required' => ['required', 'boolean'],
            'dynamicFormSchema.*.options' => ['sometimes', 'array'],
            'dynamicFormSchema.*.options.*' => ['string', 'max:100'],

            'requiresExtraApproval' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dynamicFormSchema.*.key.regex' => 'Field keys may only contain lowercase letters, numbers and underscores.',
        ];
    }
}
