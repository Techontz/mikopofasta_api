<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DisbursementBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `DisbursementBatchSchema` in the frontend's types/loan.ts.
 *
 * @mixin DisbursementBatch
 */
final class DisbursementBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'loanId' => (string) $this->loan_id,
            'batchReference' => $this->batch_reference,
            'attemptNumber' => $this->attempt_number,
            'channel' => $this->channel->value,
            'status' => $this->status->value,
            'failureReason' => $this->failure_reason,
            'requestedBy' => (string) $this->requested_by,
            'requestedAt' => $this->requested_at->toIso8601String(),
            'completedAt' => $this->completed_at?->toIso8601String(),
        ];
    }
}
