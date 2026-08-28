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
            'riskTier' => $this->risk_tier->value,
            'sector' => $this->sector->value,
            'requiredDocuments' => $this->required_documents,
            /* Which first-class registration blocks this category asks for.
               The wizard shows the sector, contract and salary sections off
               these rather than off a hardcoded list of category codes. */
            'requiresSector' => $this->requires_sector,
            /* A private-sector employee names a COMPANY, not a ministry. */
            'requiresEmployer' => $this->requires_employer,
            'requiresContract' => $this->requires_contract,
            'requiresSalary' => $this->requires_salary,
            'dynamicFormSchema' => $this->dynamic_form_schema,
            'requiresExtraApproval' => $this->requires_extra_approval,
            'createdBy' => $this->created_by === null ? null : (string) $this->created_by,
            'deletedAt' => $this->deleted_at?->toIso8601String(),
            'customerCount' => $this->whenCounted('customers'),
        ];
    }
}
