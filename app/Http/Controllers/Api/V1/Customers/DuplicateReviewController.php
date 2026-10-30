<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Customers\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerDuplicateReview;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Customers\CustomerRegistrar;
use App\Services\Customers\DuplicateCustomerCheck;
use App\Services\Customers\PossibleDuplicateCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Registrations held as possible duplicates ({@see DuplicateCustomerCheck}). KYC approvers (customers.approve) see the
 * matched customers of every branch and either register the customer (the authorized exception, with a reason) or
 * reject the registration as a duplicate. The employee who submitted it cannot decide it.
 */
class DuplicateReviewController extends ApiController
{
    public function __construct(
        private CustomerRegistrar $registrar,
        private SegregationOfDuties $duties,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('customers.approve');

        $status = $request->input('status', CustomerDuplicateReview::STATUS_PENDING);
        $reviews = CustomerDuplicateReview::query()
            ->with(['branch:id,name', 'requester:id,first_name,last_name', 'decider:id,first_name,last_name'])
            ->where('company_id', $this->currentEmployee()->company_id)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($request->filled('branch_id') && $request->input('branch_id') !== 'all', fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'data' => $reviews->getCollection()->map(fn (CustomerDuplicateReview $review): array => $this->present($review))->all(),
            'meta' => ['currentPage' => $reviews->currentPage(), 'lastPage' => $reviews->lastPage(), 'perPage' => $reviews->perPage(), 'total' => $reviews->total()],
        ]);
    }

    public function show(CustomerDuplicateReview $review): JsonResponse
    {
        $this->authorizeAny('customers.approve');
        $this->assertInCompany($review);

        return response()->json(['data' => $this->present($review->load(['branch:id,name', 'requester:id,first_name,last_name', 'decider:id,first_name,last_name']), withMatches: true)]);
    }

    public function release(Request $request, CustomerDuplicateReview $review): CustomerResource|JsonResponse
    {
        $this->authorizeAny('customers.approve');
        $this->assertInCompany($review);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $approver = $this->currentEmployee();
        $this->duties->assertCanApprove($review->requested_by, $approver);

        try {
            $customer = DB::transaction(function () use ($review, $validated, $approver): Customer {
                $locked = CustomerDuplicateReview::query()->lockForUpdate()->findOrFail($review->id);
                abort_unless($locked->status === CustomerDuplicateReview::STATUS_PENDING, 409, 'Only a registration awaiting verification can be released.');

                $customer = $this->registrar->register($locked->payload, $locked->requester ?? $approver, $locked);
                $locked->forceFill([
                    'status' => CustomerDuplicateReview::STATUS_RELEASED,
                    'decided_by' => $approver->id,
                    'decided_at' => now(),
                    'decision_reason' => trim($validated['reason']),
                    'customer_id' => $customer->id,
                ])->save();
                $this->registrar->audit($customer, 'Customer.duplicate_review_released', [
                    'review_id' => $locked->id,
                    'matches' => $locked->matches,
                    'reason' => $locked->decision_reason,
                ], [], $approver);

                return $customer;
            });
        } catch (PossibleDuplicateCustomer $exception) {
            $review->forceFill(['matches' => $exception->matches])->save();

            return $this->message('New possible matches were found since this registration was held. Review them before releasing it.', 409, ['data' => $this->present($review->refresh(), withMatches: true)]);
        }

        return (new CustomerResource($customer->load(CustomerController::RESOURCE_RELATIONS)))->response()->setStatusCode(201);
    }

    public function reject(Request $request, CustomerDuplicateReview $review): JsonResponse
    {
        $this->authorizeAny('customers.approve');
        $this->assertInCompany($review);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $approver = $this->currentEmployee();
        $this->duties->assertCanApprove($review->requested_by, $approver);

        $review = DB::transaction(function () use ($review, $validated, $approver): CustomerDuplicateReview {
            $locked = CustomerDuplicateReview::query()->lockForUpdate()->findOrFail($review->id);
            abort_unless($locked->status === CustomerDuplicateReview::STATUS_PENDING, 409, 'Only a registration awaiting verification can be rejected.');
            $locked->forceFill([
                'status' => CustomerDuplicateReview::STATUS_REJECTED,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'decision_reason' => trim($validated['reason']),
            ])->save();

            return $locked;
        });

        return response()->json(['data' => $this->present($review)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CustomerDuplicateReview $review, bool $withMatches = false): array
    {
        $payload = $review->payload;
        $row = [
            'id' => $review->id,
            'status' => $review->status,
            'name' => trim(implode(' ', array_filter([$payload['firstName'] ?? null, $payload['middleName'] ?? null, $payload['lastName'] ?? null]))),
            'phone' => $payload['phone'] ?? null,
            'nidaNumber' => $payload['nidaNumber'] ?? null,
            'branch' => $review->branch?->name,
            'reasons' => collect($review->matches)->pluck('reasons')->flatten()->unique()->values()->all(),
            'matchCount' => count($review->matches),
            'requestedBy' => $review->requester ? trim($review->requester->first_name.' '.$review->requester->last_name) : null,
            'requestedAt' => $review->created_at?->toIso8601String(),
            'decidedBy' => $review->decider ? trim($review->decider->first_name.' '.$review->decider->last_name) : null,
            'decidedAt' => $review->decided_at?->toIso8601String(),
            'decisionReason' => $review->decision_reason,
            'customerId' => $review->customer_id,
        ];

        if ($withMatches) {
            $reasons = collect($review->matches)->mapWithKeys(fn (array $match): array => [(int) $match['customer_id'] => $match['reasons']]);
            $row['payload'] = $payload;
            $row['matches'] = Customer::withTrashed()->with('branch:id,name')->whereIn('id', $reasons->keys())->get()
                ->map(fn (Customer $customer): array => [
                    'customerId' => $customer->id,
                    'customerNumber' => $customer->customer_number,
                    'name' => trim(implode(' ', array_filter([$customer->first_name, $customer->middle_name, $customer->last_name]))),
                    'phone' => $customer->phone,
                    'nidaNumber' => $customer->nida_number,
                    'checkNumber' => $customer->check_number,
                    'accountNumber' => $customer->account_number,
                    'dob' => $customer->date_of_birth?->format('Y-m-d'),
                    'branch' => $customer->branch?->name,
                    'approvalStatus' => $customer->approval_status,
                    'deleted' => $customer->trashed(),
                    'reasons' => $reasons[$customer->id] ?? [],
                ])->values()->all();
        }

        return $row;
    }

    private function assertInCompany(CustomerDuplicateReview $review): void
    {
        abort_unless((int) $review->company_id === (int) $this->currentEmployee()->company_id, 404);
    }
}
