<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EMandate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `EMandateSchema` in the frontend's types/loan.ts.
 *
 * @mixin EMandate
 */
final class EMandateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'loanId' => (string) $this->loan_id,
            'bankName' => $this->bank_name,
            'otpReference' => $this->otp_reference,
            'status' => $this->status->value,
            'failureReason' => $this->failure_reason,
            'verifiedAt' => $this->verified_at?->toIso8601String(),
        ];
    }
}
