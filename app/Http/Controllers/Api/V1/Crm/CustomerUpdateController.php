<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\CustomerUpdateRequest;
use App\Services\Customers\CustomerUpdates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM → Customer Updates: changes branch staff made to registered customers, waiting for CRM approval, and every change
 * already applied ({@see CustomerUpdates}). Approving applies the change; rejecting leaves the customer as it is.
 */
class CustomerUpdateController extends ApiController
{
    private const WITH = ['customer', 'branch', 'requester', 'decider'];

    public function __construct(private readonly CustomerUpdates $updates) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('customers.approve_updates');
        $request->validate(['status' => ['nullable', Rule::in(['all', CustomerUpdateRequest::STATUS_PENDING, CustomerUpdateRequest::STATUS_APPROVED, CustomerUpdateRequest::STATUS_REJECTED])]]);
        $status = $request->input('status', CustomerUpdateRequest::STATUS_PENDING);

        $rows = $this->applyFilters($this->scoped(CustomerUpdateRequest::query()), $request, 'customer_update_requests.created_at')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->with(self::WITH)
            ->when($status === CustomerUpdateRequest::STATUS_PENDING, fn ($query) => $query->oldest('id'), fn ($query) => $query->latest('id'))
            ->limit(1000)
            ->get();

        return response()->json(['data' => $rows->map(fn (CustomerUpdateRequest $row): array => $this->present($row))->all()]);
    }

    public function approve(CustomerUpdateRequest $updateRequest): JsonResponse
    {
        $this->authorizeRequest($updateRequest);
        $this->updates->approve($updateRequest, $this->currentEmployee());

        return $this->message('Customer update approved', 200, ['data' => $this->present($updateRequest->refresh()->load(self::WITH))]);
    }

    public function reject(Request $request, CustomerUpdateRequest $updateRequest): JsonResponse
    {
        $this->authorizeRequest($updateRequest);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->updates->reject($updateRequest, $this->currentEmployee(), trim($validated['reason']));

        return $this->message('Customer update rejected', 200, ['data' => $this->present($updateRequest->refresh()->load(self::WITH))]);
    }

    private function authorizeRequest(CustomerUpdateRequest $updateRequest): void
    {
        $this->authorizeAny('customers.approve_updates');
        abort_unless($updateRequest->company_id === $this->currentEmployee()->company_id, 404);
        $this->assertBranchAccessible((int) $updateRequest->branch_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CustomerUpdateRequest $row): array
    {
        return [
            'id' => $row->id,
            'status' => $row->status,
            'customer' => $row->customer ? ['id' => $row->customer->id, 'name' => $row->customer->full_name, 'code' => $row->customer->customer_code, 'phone' => $row->customer->phone] : null,
            'branch' => $row->branch?->name,
            'changes' => array_map(fn (array $change): array => collect($change)->only(['field', 'old', 'new'])->all(), $row->changes),
            'requested_by' => $row->requester?->full_name,
            'requested_at' => $row->created_at?->format('Y-m-d H:i'),
            'decided_by' => $row->decider?->full_name,
            'decided_at' => $row->decided_at?->format('Y-m-d H:i'),
            'rejection_reason' => $row->rejection_reason,
        ];
    }
}
