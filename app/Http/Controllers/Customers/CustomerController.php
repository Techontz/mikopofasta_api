<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Actions\DecideCustomerApprovalAction;
use App\Domain\Customers\Actions\FreezeCustomerAction;
use App\Domain\Customers\Actions\RegisterCustomerAction;
use App\Domain\Customers\Actions\SetCustomerStatusAction;
use App\Domain\Customers\Services\KycEvaluator;
use App\Domain\Organization\Services\BranchScope;
use App\Domain\Organization\Services\BranchScopeGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\FreezeCustomerRequest;
use App\Http\Requests\Customers\IndexCustomerRequest;
use App\Http\Requests\Customers\RegisterCustomerRequest;
use App\Http\Requests\Customers\RejectCustomerRequest;
use App\Http\Requests\Customers\SetCustomerStatusRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customers — registration, search, profile, approval, freeze and status.
 *
 * Every read and every write is branch-scoped (§13): a Loan Officer sees only
 * their own branch's customers, and reaching outside that returns
 * BRANCH_SCOPE_VIOLATION and is audited.
 */
final class CustomerController extends Controller
{
    public function __construct(
        private readonly BranchScope $scope,
        private readonly BranchScopeGuard $guard,
    ) {}

    /**
     * GET /api/v1/customers
     *
     * Search and the three faceted filters mirror the frontend's
     * customers-table one for one.
     */
    public function index(IndexCustomerRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $filters = $request->validated();

        $query = Customer::query()
            ->with(['branch', 'category'])
            ->when(
                isset($filters['search']) && $filters['search'] !== '',
                fn ($q) => $q->search((string) $filters['search']),
            )
            ->when(! empty($filters['kyc_status']), fn ($q) => $q->whereIn('kyc_status', $filters['kyc_status']))
            ->when(! empty($filters['status']), fn ($q) => $q->whereIn('status', $filters['status']))
            ->when(! empty($filters['approval_status']), fn ($q) => $q->whereIn('approval_status', $filters['approval_status']))
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(isset($filters['customer_category_id']), fn ($q) => $q->where('customer_category_id', $filters['customer_category_id']))
            ->when($request->boolean('include_deleted'), fn ($q) => $q->withTrashed())
            ->orderBy('last_name')
            ->orderBy('first_name');

        $query = $this->scope->applyToColumn($query, $this->actor($request));

        return ApiResponse::paginated(
            $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString(),
            CustomerResource::class,
        );
    }

    /**
     * POST /api/v1/customers — spec §15.1.
     *
     * Takes the complete registration payload the wizard assembles: identity,
     * address, category, dynamic KYC data, bank details, guarantors and
     * next-of-kin, all in one transaction.
     */
    public function store(RegisterCustomerRequest $request, RegisterCustomerAction $action): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $actor = $this->actor($request);

        // A customer may only be registered into a branch the officer can
        // actually reach — otherwise branch scoping is trivially bypassed by
        // registering into someone else's branch.
        $this->guard->authorizeBranchId($actor, (int) $request->validated('branchId'), Customer::class);

        $customer = $action->handle($request->validated(), $actor);

        return ApiResponse::data(new CustomerResource($customer), status: Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/customers/{customer}
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);
        $this->guard->authorizeBranchId($this->actor($request), $customer->branch_id, Customer::class);

        return ApiResponse::data(
            new CustomerResource($customer->load(['branch', 'category', 'bankDetails'])),
        );
    }

    /**
     * GET /api/v1/customers/{customer}/kyc-status — spec §15.1.
     *
     * The five-item checklist plus the overall status, and the required
     * documents still outstanding.
     */
    public function kycStatus(Request $request, Customer $customer, KycEvaluator $kyc): JsonResponse
    {
        $this->authorize('view', $customer);
        $this->guard->authorizeBranchId($this->actor($request), $customer->branch_id, Customer::class);

        $customer->load(['bankDetails', 'category', 'documents']);

        return ApiResponse::data([
            'customerId' => (string) $customer->getKey(),
            'checklist' => $kyc->checklist($customer),
            'kycStatus' => $customer->kyc_status->value,
            'isComplete' => $kyc->isComplete($customer),
            'missingDocuments' => $kyc->missingDocuments($customer),
            'isLoanEligible' => $customer->isLoanEligible(),
        ]);
    }

    /**
     * POST /api/v1/customers/{customer}/approve
     */
    public function approve(Request $request, Customer $customer, DecideCustomerApprovalAction $action): JsonResponse
    {
        $this->authorize('decideApproval', $customer);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Customer::class);

        return ApiResponse::data(new CustomerResource($action->approve($customer, $actor)));
    }

    /**
     * POST /api/v1/customers/{customer}/reject
     */
    public function reject(RejectCustomerRequest $request, Customer $customer, DecideCustomerApprovalAction $action): JsonResponse
    {
        $this->authorize('decideApproval', $customer);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Customer::class);

        $updated = $action->reject($customer, (string) $request->validated('reason'), $actor);

        return ApiResponse::data(new CustomerResource($updated));
    }

    /**
     * POST /api/v1/customers/{customer}/freeze
     */
    public function freeze(FreezeCustomerRequest $request, Customer $customer, FreezeCustomerAction $action): JsonResponse
    {
        $this->authorize('freeze', $customer);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Customer::class);

        $updated = $action->freeze($customer, (string) $request->validated('reason'), $actor);

        return ApiResponse::data(new CustomerResource($updated));
    }

    /**
     * POST /api/v1/customers/{customer}/unfreeze
     */
    public function unfreeze(Request $request, Customer $customer, FreezeCustomerAction $action): JsonResponse
    {
        $this->authorize('freeze', $customer);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Customer::class);

        return ApiResponse::data(new CustomerResource($action->unfreeze($customer, $actor)));
    }

    /**
     * PATCH /api/v1/customers/{customer}/status
     */
    public function setStatus(SetCustomerStatusRequest $request, Customer $customer, SetCustomerStatusAction $action): JsonResponse
    {
        $this->authorize('update', $customer);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Customer::class);

        $updated = $action->handle($customer, $request->boolean('active'), $actor);

        return ApiResponse::data(new CustomerResource($updated));
    }
}
