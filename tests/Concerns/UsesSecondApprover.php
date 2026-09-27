<?php

namespace Tests\Concerns;

use App\Models\ApprovalPolicy;
use App\Models\Employee;
use Illuminate\Testing\TestResponse;

/**
 * Rule 6 (segregation of duties) test helpers: the employee who initiates a financial transaction cannot approve it, so
 * tests approve with a second authorised employee of the same company (or grant approvals.self_approve explicitly).
 */
trait UsesSecondApprover
{
    /**
     * Another employee of the initiator's company and branch holding the given system role (Super Admin by default).
     */
    protected function secondApprover(Employee $initiator, string $role = 'super_admin'): Employee
    {
        return Employee::factory()->create([
            'company_id' => $initiator->company_id,
            'branch_id' => $initiator->branch_id,
            'role_id' => $initiator->company->roles()->where('key', $role)->value('id'),
        ]);
    }

    /**
     * Run the callback signed in as the approver, then sign the initiator back in.
     *
     * @template TResult
     *
     * @param  callable(Employee): TResult  $callback
     * @return TResult
     */
    protected function asApprover(Employee $initiator, callable $callback, ?Employee $approver = null): mixed
    {
        $approver ??= $this->secondApprover($initiator);
        $this->actingAs($approver);

        try {
            return $callback($approver);
        } finally {
            $this->actingAs($initiator);
        }
    }

    /**
     * Explicitly grant approvals.self_approve to one employee (employee override). C6: self-approval also needs the company
     * approval policy to allow it, so by default every workflow of the employee's company is allowed as well; pass false to
     * grant the permission alone.
     */
    protected function grantSelfApproval(Employee $employee, bool $withCompanyPolicy = true): void
    {
        $employee->permissionOverrides()->updateOrCreate(['permission' => 'approvals.self_approve'], ['granted' => true]);
        $employee->unsetRelation('permissionOverrides');

        if ($withCompanyPolicy) {
            $this->allowSelfApprovalPolicy((int) $employee->company_id);
        }
    }

    /**
     * Company approval policy allowing self-approval for the given workflows (all workflows by default).
     *
     * @param  list<string>|null  $workflows
     */
    protected function allowSelfApprovalPolicy(int $companyId, ?array $workflows = null): void
    {
        foreach ($workflows ?? array_keys(ApprovalPolicy::WORKFLOWS) as $workflow) {
            ApprovalPolicy::updateOrCreate(['company_id' => $companyId, 'workflow' => $workflow], ['allow_self_approval' => true]);
        }
    }

    /**
     * Maker/checker reversals: assert the reverse endpoint only created a pending request, then approve it under Reversal
     * Requests as another authorised user (a new Super Admin by default) and sign the requester back in. Returns the approval
     * response unasserted.
     */
    protected function approveReversal(TestResponse $requested, ?Employee $approver = null): TestResponse
    {
        $requested->assertCreated()->assertJsonPath('reversal_request.status', 'pending');
        $requester = auth()->user();
        $approver ??= $this->secondApprover($requester);
        $this->actingAs($approver);

        try {
            return $this->postJson('/api/v1/reversal-requests/'.$requested->json('reversal_request.id').'/approve');
        } finally {
            $this->actingAs($requester);
        }
    }

    /**
     * POST an approval endpoint as a second authorised user (asserting success) and sign the initiator back in.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function approveAsSecondUser(Employee $initiator, string $uri, array $payload = []): TestResponse
    {
        return $this->asApprover($initiator, fn () => $this->postJson($uri, $payload)->assertOk());
    }
}
