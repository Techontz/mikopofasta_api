<?php

namespace App\Services\Approvals;

use App\Models\ApprovalPolicy;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads and changes the company approval policy (C6). Defaults when a workflow has no row: approval required, self-approval
 * not allowed. Every change is audit-logged with the before / after values.
 */
class ApprovalPolicies
{
    public function allowsSelfApproval(int $companyId, string $workflow): bool
    {
        return (bool) ApprovalPolicy::where('company_id', $companyId)->where('workflow', $workflow)->value('allow_self_approval');
    }

    /**
     * Every workflow with its effective setting (defaults for workflows without a row).
     *
     * @return list<array{workflow: string, label: string, requires_approval: bool, allow_self_approval: bool, updated_by: string|null, updated_at: string|null}>
     */
    public function all(int $companyId): array
    {
        $stored = ApprovalPolicy::where('company_id', $companyId)->with('updater')->get()->keyBy('workflow');

        return collect(ApprovalPolicy::WORKFLOWS)->map(function (string $label, string $workflow) use ($stored): array {
            $policy = $stored->get($workflow);

            return [
                'workflow' => $workflow,
                'label' => $label,
                'requires_approval' => true,
                'allow_self_approval' => (bool) ($policy?->allow_self_approval ?? false),
                'updated_by' => $policy?->updater?->full_name,
                'updated_at' => $policy?->updated_at?->toDateTimeString(),
            ];
        })->values()->all();
    }

    /**
     * Set allow_self_approval for the given workflows. Unknown workflow keys are rejected; unchanged values write nothing.
     *
     * @param  array<string, bool>  $allowSelfApproval  workflow => allow
     *
     * @throws ValidationException
     */
    public function update(int $companyId, array $allowSelfApproval, Employee $employee, ?string $ipAddress = null): void
    {
        $unknown = array_diff(array_keys($allowSelfApproval), array_keys(ApprovalPolicy::WORKFLOWS));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['policies' => 'Unknown approval workflow: '.implode(', ', $unknown)]);
        }

        DB::transaction(function () use ($companyId, $allowSelfApproval, $employee, $ipAddress): void {
            foreach ($allowSelfApproval as $workflow => $allow) {
                $policy = ApprovalPolicy::where('company_id', $companyId)->where('workflow', $workflow)->lockForUpdate()->first();
                $before = (bool) ($policy?->allow_self_approval ?? false);
                if ($before === (bool) $allow) {
                    continue;
                }

                $policy ??= new ApprovalPolicy(['company_id' => $companyId, 'workflow' => $workflow, 'requires_approval' => true]);
                $policy->fill(['allow_self_approval' => (bool) $allow, 'updated_by' => $employee->id])->save();

                AuditLog::create([
                    'company_id' => $companyId,
                    'employee_id' => $employee->id,
                    'action' => 'ApprovalPolicy.updated',
                    'auditable_type' => $policy->getMorphClass(),
                    'auditable_id' => $policy->id,
                    'before' => ['workflow' => $workflow, 'allow_self_approval' => $before],
                    'after' => ['workflow' => $workflow, 'allow_self_approval' => (bool) $allow],
                    'ip_address' => $ipAddress,
                ]);
            }
        });
    }
}
