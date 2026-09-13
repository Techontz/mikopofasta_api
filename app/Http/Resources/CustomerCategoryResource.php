<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `CustomerCategorySchema` in the frontend's types/customer.ts.
 *
 * `dynamicFormSchema` goes out verbatim — the registration wizard renders its
 * category step directly from these field definitions.
 *
 * @mixin CustomerCategory
 */
final class CustomerCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            /* The heading the registration form shows over this type's own
               questions. Null on the wire, and the form falls back to the
               name — resolving it here would hide from the administrator that
               they never set one. */
            'formTitle' => $this->form_title,
            /* Whether registration may still offer it, and where in the list. */
            'isActive' => (bool) $this->is_active,
            'sortOrder' => $this->sort_order,
            'riskTier' => $this->risk_tier->value,
            'sector' => $this->sector->value,
            'requiredDocuments' => $this->required_documents,
            /* Offered on the documents step, never blocking. */
            'optionalDocuments' => $this->optional_documents ?? [],
            /* Which first-class registration blocks this category asks for.
               The wizard shows the sector, contract and salary sections off
               these rather than off a hardcoded list of category codes. */
            'requiresSector' => $this->requires_sector,
            /* A private-sector employee names a COMPANY, not a ministry. */
            'requiresEmployer' => $this->requires_employer,
            'requiresContract' => $this->requires_contract,
            'requiresSalary' => $this->requires_salary,
            'dynamicFormSchema' => $this->dynamic_form_schema,
            /* Standard questions this type declines to ask. Empty, never null, so
               the client can iterate it without a guard. */
            'omittedStandardFields' => $this->omitted_standard_fields ?? [],
            'requiresExtraApproval' => $this->requires_extra_approval,
            'createdBy' => $this->created_by === null ? null : (string) $this->created_by,
            'deletedAt' => $this->deleted_at?->toIso8601String(),
            'customerCount' => $this->whenCounted('customers'),
        ];
    }
}
