<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Customers\CustomerResource;
use App\Models\Customer;
use App\Services\Customers\CustomerRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registration approvals (CUSTOMER_MODULE_SPEC.md §1.3): approve / reject by `customers.approve`,
 * resubmit a returned registration by `customers.manage`.
 */
class CustomerApprovalController extends ApiController
{
    public function __construct(private CustomerRegistrar $registrar) {}

    public function pending(Request $request): JsonResponse
    {
        $this->authorizeAny('customers.approve');

        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $customers = $this->scoped(Customer::query())
            ->with(CustomerController::RESOURCE_RELATIONS)
            ->where('approval_status', 'pending')
            ->when($request->filled('branch_id') && $request->input('branch_id') !== 'all', fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->oldest('id')
            ->paginate($perPage);

        return response()->json([
            'data' => CustomerResource::collection($customers->getCollection())->resolve($request),
            'meta' => ['currentPage' => $customers->currentPage(), 'lastPage' => $customers->lastPage(), 'perPage' => $customers->perPage(), 'total' => $customers->total()],
        ]);
    }

    public function approve(Customer $customer): CustomerResource
    {
        $this->authorizeAny('customers.approve');
        $this->assertAccessible($customer);
        abort_unless($customer->approval_status === 'pending', 409, 'Only a registration awaiting approval can be approved.');

        $customer->forceFill(['approval_status' => 'approved', 'approved_by' => $this->currentEmployee()->id, 'approved_at' => now(), 'rejection_reason' => null])->save();
        $this->registrar->audit($customer, 'Customer.approved', ['approval_status' => 'approved'], ['approval_status' => 'pending']);

        return $this->resource($customer);
    }

    public function reject(Request $request, Customer $customer): CustomerResource
    {
        $this->authorizeAny('customers.approve');
        $this->assertAccessible($customer);
        abort_unless($customer->approval_status === 'pending', 409, 'Only a registration awaiting approval can be rejected.');

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $customer->forceFill(['approval_status' => 'rejected', 'approved_by' => null, 'approved_at' => null, 'rejection_reason' => trim($validated['reason'])])->save();
        $this->registrar->audit($customer, 'Customer.rejected', ['approval_status' => 'rejected', 'reason' => $customer->rejection_reason], ['approval_status' => 'pending']);

        return $this->resource($customer);
    }

    public function resubmit(Customer $customer): CustomerResource
    {
        $this->authorizeAny('customers.manage');
        $this->assertAccessible($customer);
        abort_unless($customer->approval_status === 'rejected', 409, 'Only a returned registration can be resubmitted.');

        $customer->forceFill(['approval_status' => 'pending', 'rejection_reason' => null])->save();
        $this->registrar->audit($customer, 'Customer.resubmitted', ['approval_status' => 'pending'], ['approval_status' => 'rejected']);

        return $this->resource($customer);
    }

    private function resource(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer->load([...CustomerController::RESOURCE_RELATIONS, 'bankDetail', 'nextOfKins', 'guarantors', 'documents']));
    }

    private function assertAccessible(Customer $customer): void
    {
        abort_unless($this->scoped(Customer::query())->whereKey($customer->id)->exists(), 404);
    }
}
