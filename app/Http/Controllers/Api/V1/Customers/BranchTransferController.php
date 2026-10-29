<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Customer;
use App\Models\CustomerBranchTransfer;
use App\Services\AccessControl;
use App\Services\Customers\CustomerBranchTransfers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Customer branch transfers ({@see CustomerBranchTransfers}): requested by customers.transfer for a customer the employee
 * can see, decided by customers.approve_transfer (never the requester). Approvers see every branch's requests; others
 * see the requests of customers in their scope.
 */
class BranchTransferController extends ApiController
{
    public function __construct(private CustomerBranchTransfers $transfers) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('customers.transfer', 'customers.approve_transfer');

        $status = $request->input('status', CustomerBranchTransfer::STATUS_PENDING);
        $query = CustomerBranchTransfer::query()
            ->with(['customer:id,customer_number,first_name,middle_name,last_name,branch_id', 'fromBranch:id,name', 'toBranch:id,name', 'requester:id,first_name,last_name', 'decider:id,first_name,last_name'])
            ->where('company_id', $this->currentEmployee()->company_id)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')));

        if (! Gate::allows('customers.approve_transfer')) {
            $branches = app(AccessControl::class)->branchIds($this->currentEmployee());
            if ($branches !== null) {
                $query->where(fn ($query) => $query->whereIn('from_branch_id', $branches)->orWhereIn('to_branch_id', $branches));
            }
        }

        $transfers = $query->latest('id')->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'data' => $transfers->getCollection()->map(fn (CustomerBranchTransfer $transfer): array => $this->present($transfer))->all(),
            'meta' => ['currentPage' => $transfers->currentPage(), 'lastPage' => $transfers->lastPage(), 'perPage' => $transfers->perPage(), 'total' => $transfers->total()],
        ]);
    }

    public function store(Request $request, int $customer): JsonResponse
    {
        $this->authorizeAny('customers.transfer');
        $customer = $this->scoped(Customer::query())->findOrFail($customer);
        $validated = $request->validate([
            'to_branch_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $transfer = $this->transfers->request($customer, (int) $validated['to_branch_id'], $validated['reason'], $this->currentEmployee());

        return response()->json(['data' => $this->present($transfer->load(['fromBranch:id,name', 'toBranch:id,name']))], 201);
    }

    public function approve(Request $request, CustomerBranchTransfer $transfer): JsonResponse
    {
        $this->authorizeAny('customers.approve_transfer');
        $this->assertInCompany($transfer);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $transfer = $this->transfers->approve($transfer, $this->currentEmployee(), $validated['note'] ?? null);

        return response()->json(['data' => $this->present($transfer->load(['fromBranch:id,name', 'toBranch:id,name']))]);
    }

    public function reject(Request $request, CustomerBranchTransfer $transfer): JsonResponse
    {
        $this->authorizeAny('customers.approve_transfer');
        $this->assertInCompany($transfer);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $transfer = $this->transfers->reject($transfer, $this->currentEmployee(), $validated['reason']);

        return response()->json(['data' => $this->present($transfer)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CustomerBranchTransfer $transfer): array
    {
        $customer = $transfer->customer;

        return [
            'id' => $transfer->id,
            'status' => $transfer->status,
            'customerId' => $transfer->customer_id,
            'customerNumber' => $customer?->customer_number,
            'customerName' => $customer ? trim(implode(' ', array_filter([$customer->first_name, $customer->middle_name, $customer->last_name]))) : null,
            'fromBranchId' => $transfer->from_branch_id,
            'fromBranch' => $transfer->fromBranch?->name,
            'toBranchId' => $transfer->to_branch_id,
            'toBranch' => $transfer->toBranch?->name,
            'reason' => $transfer->reason,
            'requestedBy' => $transfer->requester ? trim($transfer->requester->first_name.' '.$transfer->requester->last_name) : null,
            'requestedAt' => $transfer->created_at?->toIso8601String(),
            'decidedBy' => $transfer->decider ? trim($transfer->decider->first_name.' '.$transfer->decider->last_name) : null,
            'decidedAt' => $transfer->decided_at?->toIso8601String(),
            'decisionReason' => $transfer->decision_reason,
            'moved' => $transfer->moved,
            'journalEntryId' => $transfer->journal_entry_id,
        ];
    }

    private function assertInCompany(CustomerBranchTransfer $transfer): void
    {
        abort_unless((int) $transfer->company_id === (int) $this->currentEmployee()->company_id, 404);
    }
}
