<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Customers\CustomerResource;
use App\Models\Customer;
use App\Services\Customers\CustomerRegistrar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registration approvals (CUSTOMER_MODULE_SPEC.md §1.3): approve / reject by `customers.approve`,
 * resubmit a returned registration by `customers.manage`.
 */
class CustomerApprovalController extends ApiController
{
    public function __construct(private CustomerRegistrar $registrar) {}

    /**
     * KYC Approval list, filtered by the status tab: awaiting (registration awaiting a decision with complete KYC),
     * kyc_incomplete (awaiting, KYC still missing items), returned (rejected back to the officer), approved, or all.
     * Every answer carries the count of each tab in the same branch scope.
     */
    public function pending(Request $request): JsonResponse
    {
        $this->authorizeAny('customers.approve');

        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $status = in_array($request->input('status'), array_keys(self::TABS), true) ? (string) $request->input('status') : 'pending';
        $base = fn () => $this->scoped(Customer::query())
            ->when($request->filled('branch_id') && $request->input('branch_id') !== 'all', fn ($query) => $query->where('branch_id', $request->integer('branch_id')));

        $customers = self::tab($base(), $status)
            ->with(CustomerController::RESOURCE_RELATIONS)
            ->when(in_array($status, ['approved', 'returned', 'all'], true), fn ($query) => $query->latest('updated_at'), fn ($query) => $query->oldest('id'))
            ->paginate($perPage);

        return response()->json([
            'data' => CustomerResource::collection($customers->getCollection())->resolve($request),
            'meta' => ['currentPage' => $customers->currentPage(), 'lastPage' => $customers->lastPage(), 'perPage' => $customers->perPage(), 'total' => $customers->total()],
            'counts' => collect(array_keys(self::TABS))->mapWithKeys(fn (string $tab): array => [$tab => self::tab($base(), $tab)->count()])->all(),
        ]);
    }

    /**
     * @var array<string, string>
     */
    private const TABS = [
        'pending' => 'Awaiting approval (all)',
        'awaiting' => 'Awaiting approval, KYC complete',
        'kyc_incomplete' => 'Awaiting approval, KYC incomplete',
        'returned' => 'Returned to the officer',
        'approved' => 'Approved',
        'all' => 'All registrations',
    ];

    private static function tab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'awaiting' => $query->where('approval_status', 'pending')->where('kyc_status', 'completed'),
            'kyc_incomplete' => $query->where('approval_status', 'pending')->where('kyc_status', '!=', 'completed'),
            'returned' => $query->where('approval_status', 'rejected'),
            'approved' => $query->where('approval_status', 'approved'),
            'all' => $query->whereIn('approval_status', ['pending', 'rejected', 'approved']),
            default => $query->where('approval_status', 'pending'),
        };
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
