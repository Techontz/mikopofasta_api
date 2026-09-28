<?php

namespace App\Http\Resources\Api\V1\Hrm;

use App\Models\NegligenceDeduction;
use App\Models\NegligenceRecovery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Employee Portal (spec §23 / §60) Finance-approved negligence / loss deduction of the signed-in employee with its recoveries from
 * commission and the balance carried forward.
 *
 * @mixin NegligenceDeduction
 */
class MyNegligenceDeductionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'amount' => (float) $this->amount,
            'recovered_amount' => (float) $this->recovered_amount,
            'outstanding_amount' => $this->outstandingAmount(),
            'status' => $this->status,
            'status_label' => ucfirst((string) $this->status),
            'approved_at' => $this->approved_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateString(),
            'recoveries' => $this->whenLoaded('recoveries', fn () => $this->recoveries->sortBy('id')->map(fn (NegligenceRecovery $recovery): array => [
                'period' => $recovery->period?->format('Y-m'),
                'period_label' => $recovery->period?->format('F Y'),
                'commission' => (float) $recovery->commission,
                'amount' => (float) $recovery->amount,
                'outstanding_after' => (float) $recovery->outstanding_after,
                'recovered_on' => $recovery->created_at?->toDateString(),
            ])->values()->all()),
        ];
    }
}
