<?php

namespace App\Http\Resources\Api\V1\SalaryAdvance;

use App\Models\SalaryAdvance;
use App\Models\SalaryAdvancePayment;
use App\Services\DashboardStatistics;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SalaryAdvance
 */
class SalaryAdvanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $startDate = CarbonImmutable::parse($this->approved_at ?? $this->created_at);

        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'branch' => $this->branch?->name,
            'customer_id' => $this->customer_id,
            'customer' => $this->customer?->full_name,
            'phone' => $this->customer?->phone,
            'category' => $this->category?->name,
            'amount' => (float) $this->amount,
            'interest_rate' => (float) $this->interest_rate,
            'total_payable' => (float) $this->total_payable,
            'paid_amount' => $this->paid_amount,
            'remaining_amount' => $this->remaining_amount,
            'fee' => (float) $this->fee,
            'fee_status' => $feeStatus = $this->resource->feeStatus(),
            'fee_collectable' => $feeStatus === SalaryAdvance::FEE_UNCOLLECTED && in_array($this->status, ['active', 'done'], true) && $this->reversed_at === null,
            'fee_collected_at' => $this->fee_collected_at?->format('Y-m-d H:i:s'),
            'fee_collected_by' => $this->fee_collected_by === null ? null : $this->feeCollector?->full_name,
            'fee_collection_method' => $this->fee_collection_method,
            'status' => $this->status,
            'is_legacy_opening' => (bool) $this->is_legacy_opening,
            'opening_paid' => (float) $this->opening_paid,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),
            'start_date' => $startDate->format('Y-m-d H:i:s'),
            'end_date' => today()->addMonthNoOverflow()->day(5)->toDateString(),
            'alert' => DashboardStatistics::repaymentCycleEnded($this->resource) ? 'old' : 'new',
            'payments' => $this->whenLoaded('allPayments', fn () => $this->depositHistory($request)),
        ];
    }

    /**
     * Every deposit, reversed ones included, with whether the viewer may request its reversal now. Only the newest standing
     * deposit can be reversed (the full check runs again when the request is sent and when it is approved).
     *
     * @return list<array<string, mixed>>
     */
    private function depositHistory(Request $request): array
    {
        $viewer = $request->user();
        $standing = $this->allPayments->whereNull('reversed_at');
        $newest = $standing->sortBy([['paid_on', 'desc'], ['id', 'desc']])->first();

        return $this->allPayments->map(function (SalaryAdvancePayment $payment) use ($viewer, $newest): array {
            $pending = $payment->reversalRequests->first();
            $blocked = match (true) {
                $payment->reversed_at !== null => null,
                $pending !== null => 'Reversal requested by '.($pending->requester?->full_name ?? 'another user').'; waiting for approval under Reversal Requests.',
                $this->reversed_at !== null || $this->status === 'reversed' => 'The salary advance has been reversed.',
                $payment->id !== $newest?->id => 'Reverse the newest deposit first.',
                $viewer !== null && $payment->journalEntry?->employee_id !== null && (int) $payment->journalEntry->employee_id === (int) $viewer->id => 'You recorded this deposit; another user must request its reversal.',
                default => null,
            };

            return [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'paid_on' => $payment->paid_on->toDateString(),
                'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
                'reversed' => $payment->reversed_at !== null,
                'reversed_at' => $payment->reversed_at?->format('Y-m-d H:i:s'),
                'reversed_by' => $payment->reverser?->full_name,
                'reversal_reason' => $payment->reversal_reason,
                'reversal_pending' => $pending !== null,
                'can_request_reversal' => $payment->reversed_at === null && $blocked === null,
                'reversal_blocked_reason' => $blocked,
            ];
        })->values()->all();
    }
}
