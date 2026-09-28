<?php

namespace App\Http\Resources\Api\V1\Settings;

use App\Models\CustomerCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer type resource (CUSTOMER_MODULE_IMPLEMENTATION.md §3.3). No loan fields: loan categories and their limits belong
 * to the loan categories that reference the customer type.
 *
 * @mixin CustomerCategory
 */
class CustomerCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'formTitle' => $this->form_title,
            'isActive' => (bool) $this->is_active,
            'sortOrder' => (int) $this->sort_order,
            'riskTier' => $this->risk_tier,
            'sector' => $this->sector,
            'requiredDocuments' => $this->required_documents ?? [],
            'optionalDocuments' => $this->optional_documents ?? [],
            'requiresSector' => (bool) $this->requires_sector,
            'requiresEmployer' => (bool) $this->requires_employer,
            'requiresContract' => (bool) $this->requires_contract,
            'requiresSalary' => (bool) $this->requires_salary,
            'dynamicFormSchema' => $this->dynamic_form_schema ?? [],
            'omittedStandardFields' => $this->omitted_standard_fields ?? [],
            'requiresExtraApproval' => (bool) $this->requires_extra_approval,
            'createdBy' => $this->created_by,
            'deletedAt' => $this->deleted_at?->toIso8601String(),
            'customerCount' => (int) ($this->customers_count ?? $this->customers()->count()),
        ];
    }
}
