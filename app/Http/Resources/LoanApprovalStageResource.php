<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoanApprovalStage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One tier of the approval chain.
 *
 * @mixin LoanApprovalStage
 */
final class LoanApprovalStageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'sequence' => $this->sequence,
            'loanStatus' => $this->loan_status->value,
            'requiredPermission' => $this->required_permission,
            'requiresMandateBefore' => $this->requires_mandate_before,
            'isActive' => $this->is_active,
        ];
    }
}
