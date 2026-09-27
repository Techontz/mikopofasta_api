<?php

namespace App\Http\Resources\Api\V1\Hrm;

use App\Models\StaffDeduction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Employee Portal (spec §60) other salary deduction of the signed-in employee, withheld one instalment per payroll.
 *
 * @mixin StaffDeduction
 */
class MyStaffDeductionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'amount' => (float) $this->amount,
            'instalments' => $this->instalments,
            'instalment_amount' => (float) $this->instalment_amount,
            'paid_amount' => (float) $this->paid_amount,
            'outstanding_amount' => round(max(0, (float) $this->amount - (float) $this->paid_amount), 2),
            'status' => $this->status,
            'created_at' => $this->created_at?->toDateString(),
        ];
    }
}
