<?php

namespace App\Http\Resources\Api\V1\Hrm;

use App\Enums\StaffCreditStatus;
use App\Models\Employee;
use App\Models\StaffLoan;
use App\Models\StaffSalaryAdvance;
use App\Services\Hrm\StaffCredit;
use Illuminate\Http\Request;

/**
 * Workflow fields shared by the staff loan and staff salary advance resources: status label, who/when of every stage (§49/§50)
 * and the next step the viewer may take (rule 6 flags).
 *
 * @property StaffLoan|StaffSalaryAdvance $resource
 */
trait PresentsStaffCreditWorkflow
{
    /**
     * @return array<string, mixed>
     */
    protected function workflow(Request $request): array
    {
        $credit = $this->resource;
        $name = fn (string $relation): ?string => $credit->relationLoaded($relation) ? $credit->getRelation($relation)?->full_name : null;

        return [
            'status' => $credit->status,
            'status_label' => StaffCreditStatus::tryFrom((string) $credit->status)?->label() ?? $credit->status,
            'review_stage' => $credit->review_stage,
            'requested_by' => $credit->requested_by,
            'requested_by_name' => $name('requester'),
            'approved_by' => $credit->approved_by,
            'approved_by_name' => $name('approver'),
            'approved_at' => $credit->approved_at?->toDateTimeString(),
            'finance_approved_by' => $credit->finance_approved_by,
            'finance_approved_by_name' => $name('financeApprover'),
            'finance_approved_at' => $credit->finance_approved_at?->toDateTimeString(),
            'disbursed_by' => $credit->disbursed_by,
            'disbursed_by_name' => $name('disburser'),
            'disbursed_at' => $credit->disbursed_at?->toDateTimeString(),
            'rejected_by' => $credit->rejected_by,
            'rejected_by_name' => $name('rejecter'),
            'rejected_at' => $credit->rejected_at?->toDateTimeString(),
            'rejection_reason' => $credit->rejection_reason,
            'completed_at' => $credit->completed_at?->toDateTimeString(),
            ...$this->nextStepFlags($request),
        ];
    }

    /**
     * @return array{next_action: string|null, can_approve: bool, approve_blocked_reason: string|null}
     */
    private function nextStepFlags(Request $request): array
    {
        $viewer = $request->user();
        if (! $viewer instanceof Employee) {
            return ['next_action' => null, 'can_approve' => false, 'approve_blocked_reason' => null];
        }

        $step = app(StaffCredit::class)->nextStep($this->resource, $viewer);

        return [
            'next_action' => $step['permitted'] ? $step['action'] : null,
            'can_approve' => $step['permitted'] && $step['blocked_reason'] === null,
            'approve_blocked_reason' => $step['blocked_reason'],
        ];
    }
}
