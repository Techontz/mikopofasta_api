<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoanStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `LoanStatusHistorySchema` in the frontend's types/loan.ts —
 * the §10 audit trail the loan timeline renders.
 *
 * @mixin LoanStatusHistory
 */
final class LoanStatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'loanId' => (string) $this->loan_id,
            'fromStatus' => $this->from_status?->value,
            'toStatus' => $this->to_status->value,
            'changedBy' => $this->changed_by === null ? null : (string) $this->changed_by,
            'reason' => $this->reason,
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
