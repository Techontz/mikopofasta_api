<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Actions\DecideCustomerApprovalAction;
use App\Domain\Customers\Actions\FreezeCustomerAction;
use App\Domain\Customers\Actions\RegisterCustomerAction;
use App\Domain\Customers\Actions\SetCustomerStatusAction;
use App\Domain\Customers\DTOs\KycRequirement;
use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Services\ExternalVerificationStatus;
use App\Domain\Customers\Services\KycEvaluator;
use App\Domain\Customers\Services\RegistrationProgress;
use App\Domain\Organization\Services\BranchScope;
use App\Domain\Organization\Services\BranchScopeGuard;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\FreezeCustomerRequest;
use App\Http\Requests\Customers\IndexCustomerRequest;
use App\Http\Requests\Customers\RegisterCustomerRequest;
use App\Http\Requests\Customers\RejectCustomerRequest;
use App\Http\Requests\Customers\SetCustomerStatusRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Http\Resources\AccountFreezeResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\AuditLogger;
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
            /* The loan applicant selector's filter. One scope, shared with
               Customer::isLoanEligible(), so the list and the loan gate can
               never disagree about who may borrow. */
            ->when(! empty($filters['loan_eligible']), fn ($q) => $q->loanEligible())
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
            /* `faceScanOperator` so the profile can name who ran the scan
               without a second request for one string. */
            new CustomerResource($customer->load(['branch', 'category', 'bankDetails', 'faceScanOperator'])),
        );
    }

    /**
     * GET /api/v1/customers/pending-approval — the manager's queue.
     *
     * A list endpoint of its own rather than a filter on the index, because
     * the decision needs facts the index does not carry: whether the face scan
     * passed, who registered the customer, and — the one that decides whether
     * the Approve button is even offered — what is still outstanding.
     * Computing that per row in the client would mean one kyc-status request
     * per customer.
     *
     * Branch-scoped like every other customer read: a Branch Manager sees
     * their own branch's queue and nobody else's.
     */
    public function pendingApproval(
        Request $request,
        KycEvaluator $kyc,
        BranchScope $scope,
    ): JsonResponse {
        $this->authorize('viewAny', Customer::class);
        $actor = $this->actor($request);

        $customers = $scope->applyToColumn(
            Customer::query()
                ->with(['branch', 'category', 'accountType', 'creator', 'bankDetails'])
                ->where('approval_status', CustomerApprovalStatus::Pending),
            $actor,
        )->orderBy('created_at')->get();

        return ApiResponse::data(
            $customers->map(fn (Customer $customer): array => [
                'id' => (string) $customer->getKey(),
                'customerNumber' => $customer->customer_number,
                'fullName' => $customer->fullName(),
                'phone' => $customer->phone,
                'branchName' => $customer->branch?->name,
                'categoryName' => $customer->category?->name,
                'accountTypeName' => $customer->accountType?->name,
                'registeredById' => $customer->created_by === null ? null : (string) $customer->created_by,
                'registeredByName' => $customer->creator?->name,
                'registeredAt' => $customer->created_at?->toDateString(),
                'kycStatus' => $customer->kyc_status->value,
                /* The scan, not the checklist item — a manager asks whether the
                   face was verified, and the checklist can be complete for an
                   account type that does not require one. */
                'faceVerified' => $customer->face_verified_at !== null,
                /* Empty means approvable. This is the same list the action
                   itself checks before it will accept a decision. */
                'outstanding' => $kyc->outstanding($customer),
                'requiresExtraApproval' => $customer->category?->needsApproval() ?? false,
            ])->all(),
        );
    }

    /**
     * GET /api/v1/customers/{customer}/kyc-status — spec §15.1.
     *
     * The five-item checklist plus the overall status, and the required
     * documents still outstanding.
     */
    public function kycStatus(
        Request $request,
        Customer $customer,
        KycEvaluator $kyc,
        RegistrationProgress $progress,
        ExternalVerificationStatus $external,
    ): JsonResponse {
        $this->authorize('view', $customer);
        $this->guard->authorizeBranchId($this->actor($request), $customer->branch_id, Customer::class);

        $customer->load(['bankDetails', 'category', 'documents']);

        return ApiResponse::data([
            'customerId' => (string) $customer->getKey(),
            /* The original five-key map. Published since Phase 2 and still
               read by the frontend contract test, so it stays. */
            'checklist' => $kyc->checklist($customer),
            /*
             * The real checklist: one line per requirement, each saying
             * whether it is satisfied, whether it is required for THIS
             * customer's account type, and — for NIDA and SMS — whether it is
             * required but impossible in this deployment. The flat map above
             * cannot express any of that.
             */
            'requirements' => array_map(
                static fn (KycRequirement $r): array => $r->toArray(),
                $kyc->requirements($customer),
            ),
            'kycStatus' => $customer->kyc_status->value,
            'isComplete' => $kyc->isComplete($customer),
            'missingDocuments' => $kyc->missingDocuments($customer),
            'isLoanEligible' => $customer->isLoanEligible(),
            /*
             * Where the registration stands, in the branch's own words, and
             * what to do next. Derived — see RegistrationStage for why there
             * is no column.
             */
            'progress' => $progress->describe($customer),
            /* Whether this deployment can perform the external checks at all,
               so the profile can say so rather than showing an unexplained ✗. */
            'externalVerification' => $external->summary(),
        ]);
    }

    /**
     * GET /api/v1/customers/{customer}/freezes
     *
     * The freeze history, newest first.
     *
     * `account_freezes` has been written since Phase 4 and never read back:
     * freeze and unfreeze were POSTs with no counterpart, so the profile could
     * show that an account IS frozen and never who froze it, when, or why —
     * and a customer frozen three times looked identical to one frozen once.
     * The table exists precisely so those questions are answerable (see the
     * model's own note), which needs an endpoint.
     */
    public function freezes(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);
        $this->guard->authorizeBranchId($this->actor($request), $customer->branch_id, Customer::class);

        return ApiResponse::data(
            AccountFreezeResource::collection($customer->freezes()->get()),
        );
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
    /**
     * POST /api/v1/customers/{customer}/resubmit
     *
     * The officer's side of a returned registration: corrected, and sent back
     * to the approver. Gated on `customers.manage` (the `update` policy)
     * rather than `customers.approve` — this is origination, not a decision.
     */
    public function resubmitRegistration(
        Request $request,
        Customer $customer,
        DecideCustomerApprovalAction $action,
    ): JsonResponse {
        $this->authorize('update', $customer);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Customer::class);

        return ApiResponse::data(new CustomerResource($action->resubmit($customer, $actor)));
    }

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

        $updated = $action->handle(
            $customer,
            $request->boolean('active'),
            [
                'reason' => (string) $request->validated('reason'),
                'remarks' => $request->validated('remarks'),
            ],
            $actor,
        );

        return ApiResponse::data(new CustomerResource($updated));
    }

    /**
     * PUT /api/v1/customers/{customer}
     *
     * The profile's save. Until this existed, only six fields could be amended
     * after registration — a surname typed wrongly on the day stayed wrong.
     *
     * Only the keys present in the request are written, so a section of the
     * profile saves without the others sending stale values back. The camelCase
     * → column map is explicit rather than a `Str::snake` loop, because the two
     * do not always agree (`nationalIdNumber` → `national_id_number` does, but
     * a silent mismatch elsewhere would drop an edit without failing).
     *
     * Audited: `AuditLogger` records the actor and the changed attributes, so a
     * corrected record still says who corrected it.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer, AuditLogger $audit): JsonResponse
    {
        $this->authorize('update', $customer);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Customer::class);

        $payload = $request->validated();

        /** @var array<string, string> $map */
        $map = [
            'firstName' => 'first_name', 'middleName' => 'middle_name', 'lastName' => 'last_name',
            'nickname' => 'nickname', 'dob' => 'dob', 'gender' => 'gender',
            'nationality' => 'nationality',
            'phone' => 'phone', 'alternativePhone' => 'alternative_phone', 'email' => 'email',
            'nationalIdNumber' => 'national_id_number', 'voterIdNumber' => 'voter_id_number',
            'driverLicenceNumber' => 'driver_licence_number', 'passportNumber' => 'passport_number',
            'tinNumber' => 'tin_number', 'workIdNumber' => 'work_id_number',
            'branchId' => 'branch_id', 'employeeId' => 'employee_id',
            'customerCategoryId' => 'customer_category_id', 'loanTypeId' => 'loan_type_id',
            'customerTypeId' => 'customer_type_id', 'accountTypeId' => 'account_type_id',
            'workTypeId' => 'work_type_id', 'employmentTypeId' => 'employment_type_id',
            'occupationId' => 'occupation_id', 'maritalStatusId' => 'marital_status_id',
            'bankId' => 'bank_id', 'mobileMoneyProviderId' => 'mobile_money_provider_id',
            'regionId' => 'region_id', 'districtId' => 'district_id', 'wardId' => 'ward_id',
            'streetId' => 'street_id', 'wardName' => 'ward_name', 'streetName' => 'street_name',
            'village' => 'village', 'houseNumber' => 'house_number',
            'postalCode' => 'postal_code', 'landmark' => 'landmark', 'residenceType' => 'residence_type',
            'occupation' => 'occupation', 'employer' => 'employer', 'department' => 'department',
            'workType' => 'work_type', 'employmentType' => 'employment_type',
            'councilNumber' => 'council_number', 'placeOfEmployment' => 'place_of_employment',
            'retirementDate' => 'retirement_date', 'dependentsCount' => 'dependents_count',
            'monthlyIncome' => 'monthly_income', 'basicSalary' => 'basic_salary', 'takeHome' => 'take_home',
            /* Identity, sector and contract — 2026_08_30. Editable after
               registration for the same reason every other field here is: a
               contract renewed or a cadre changed is an ordinary correction. */
            'idTypeId' => 'id_type_id', 'idNumber' => 'id_number',
            'sectorId' => 'sector_id', 'sectorCategoryId' => 'sector_category_id',
            'contractTypeId' => 'contract_type_id', 'contractExpiryDate' => 'contract_expiry_date',
            'employerId' => 'employer_id',
            'businessName' => 'business_name', 'businessType' => 'business_type',
            'businessAddress' => 'business_address',
            'bankName' => 'bank_name', 'bankBranch' => 'bank_branch', 'accountName' => 'account_name',
            'accountNumber' => 'account_number', 'checkNumber' => 'check_number',
            'mobileMoneyProvider' => 'mobile_money_provider', 'walletNumber' => 'wallet_number',
            'cardExpiryMonth' => 'card_expiry_month', 'cardExpiryYear' => 'card_expiry_year',
            'updatedDevice' => 'updated_device',
        ];

        $changes = [];
        foreach ($map as $key => $column) {
            if (array_key_exists($key, $payload)) {
                $changes[$column] = $payload[$key];
            }
        }

        /* Same rule as registration: the PAN never reaches a column. */
        if (array_key_exists('cardNumber', $payload)) {
            $digits = preg_replace('/\\D/', '', (string) $payload['cardNumber']);
            $changes['card_last_four'] = $digits === '' ? null : substr($digits, -4);
        }

        $customer->fill($changes);
        $dirty = $customer->getDirty();
        /* The prior values of exactly the columns about to change — so the
           audit row answers "what was it before?" and not just "it changed". */
        $before = array_intersect_key($customer->getOriginal(), $dirty);
        $customer->save();

        if ($dirty !== []) {
            $audit->log(AuditAction::CustomerUpdated, $customer, before: $before, after: $dirty, actor: $actor);
        }

        return ApiResponse::data(new CustomerResource($customer->refresh()));
    }
}
