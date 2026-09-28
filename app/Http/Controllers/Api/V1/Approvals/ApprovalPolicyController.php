<?php

namespace App\Http\Controllers\Api\V1\Approvals;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\ApprovalPolicy;
use App\Services\Approvals\ApprovalPolicies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings → Approval Policy (C6): per workflow, whether the initiator may approve their own item. Self-approval also needs the
 * employee to hold `approvals.self_approve` explicitly. Approval itself is always required (maker/checker is mandatory).
 * Every change is audit-logged.
 */
class ApprovalPolicyController extends ApiController
{
    public function __construct(private readonly ApprovalPolicies $policies) {}

    public function index(): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        return response()->json(['data' => $this->policies->all((int) $this->currentEmployee()->company_id)]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $validated = $request->validate([
            'policies' => ['required', 'array', 'min:1'],
            'policies.*.workflow' => ['required', 'string', 'distinct', Rule::in(array_keys(ApprovalPolicy::WORKFLOWS))],
            'policies.*.allow_self_approval' => ['required', 'boolean'],
        ]);

        $this->policies->update(
            (int) $this->currentEmployee()->company_id,
            collect($validated['policies'])->mapWithKeys(fn (array $policy): array => [$policy['workflow'] => (bool) $policy['allow_self_approval']])->all(),
            $this->currentEmployee(),
            $request->ip(),
        );

        return $this->message('Approval Policy Updated successfully', 200, ['data' => $this->policies->all((int) $this->currentEmployee()->company_id)]);
    }
}
