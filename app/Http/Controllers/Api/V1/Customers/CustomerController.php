<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Enums\LoanStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Customers\StoreCustomerRequest;
use App\Http\Requests\Api\Customers\UpdateCustomerRequest;
use App\Http\Resources\Api\V1\Customers\CustomerResource;
use App\Integrations\Sms\SmsGateway;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\CustomerNote;
use App\Models\Employee;
use App\Models\FaceScan;
use App\Models\Loan;
use App\Models\SmsLog;
use App\Services\CustomerEligibility;
use App\Services\Customers\CustomerDebt;
use App\Services\Customers\CustomerRegistrar;
use App\Services\Customers\KycStatusCalculator;
use App\Services\LoanService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Customer module: All Customer list, registration, Customer Profile and its tabs
 * (CUSTOMER_MODULE_SPEC.md §1, CUSTOMER_MODULE_IMPLEMENTATION.md §3.1).
 */
class CustomerController extends ApiController
{
    /**
     * Relations the customer resource shows names from.
     *
     * @var list<string>
     */
    public const RESOURCE_RELATIONS = ['branch:id,name', 'customerCategory:id,name', 'idType:id,name', 'region:id,name', 'employee:id,first_name,middle_name,last_name', 'faceScannedBy:id,first_name,middle_name,last_name', 'group:id,name'];

    public function __construct(private CustomerRegistrar $registrar, private KycStatusCalculator $kyc) {}

    /**
     * GET /customers — filters: search, kyc_status, status, approval_status, loan_eligible, branch_id,
     * customer_category_id, include_deleted, page, per_page.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('customers.view');

        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $search = trim($request->string('search')->toString());

        $customers = $this->scoped(Customer::query())
            ->with(self::RESOURCE_RELATIONS)
            ->when($request->boolean('include_deleted'), fn (Builder $query) => $query->withTrashed())
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where('first_name', 'like', $like)
                    ->orWhere('middle_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) like ?", [$like])
                    ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) like ?", [$like])
                    ->orWhere('customer_number', 'like', $like)
                    ->orWhere('customer_code', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('nida_number', 'like', $like)
                    ->orWhere('id_number', 'like', $like);
            }))
            ->when($request->filled('kyc_status'), fn (Builder $query) => $query->where('kyc_status', $request->string('kyc_status')->toString()))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('account_status', $request->string('status')->toString()))
            ->when($request->filled('approval_status'), fn (Builder $query) => $query->where('approval_status', $request->string('approval_status')->toString()))
            ->when($request->filled('branch_id') && $request->input('branch_id') !== 'all', fn (Builder $query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('customer_category_id'), fn (Builder $query) => $query->where('customer_category_id', $request->integer('customer_category_id')))
            ->when($request->filled('loan_eligible'), function (Builder $query) use ($request): void {
                $eligible = fn (Builder $query) => $query->where('kyc_status', KycStatusCalculator::COMPLETED)
                    ->where(fn (Builder $query) => $query->whereNull('customer_category_id')->orWhereHas('customerCategory', fn (Builder $query) => $query->where('is_active', true)));

                $request->boolean('loan_eligible') ? $eligible($query) : $query->whereNot(fn (Builder $query) => $eligible($query));
            })
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'data' => CustomerResource::collection($customers->getCollection())->resolve($request),
            'meta' => ['currentPage' => $customers->currentPage(), 'lastPage' => $customers->lastPage(), 'perPage' => $customers->perPage(), 'total' => $customers->total()],
        ]);
    }

    /**
     * GET /customers/registration-options — branch, officer and lock state for the wizard's Registration group.
     */
    public function registrationOptions(): JsonResponse
    {
        $this->authorizeAny('customers.manage', 'customers.edit');

        $actor = $this->currentEmployee();
        $viewAll = Gate::allows('branches.view_all');
        $branches = $this->visibleBranches()
            ->filter(fn ($branch): bool => ! $branch->is_head_office && $branch->status === 'active')
            ->when(! $viewAll, fn ($branches) => $branches->where('id', $actor->branch_id))
            ->values();

        $officers = $this->scoped(Employee::query())->staff()
            ->where('status', 'active')
            ->orderBy('first_name')
            ->get()
            ->push($actor)
            ->unique('id')
            ->when(! Gate::allows('customers.assign_officer'), fn ($officers) => $officers->where('id', $actor->id))
            ->map(fn (Employee $employee): array => ['id' => $employee->id, 'name' => $employee->full_name, 'branchId' => $employee->branch_id === null ? null : (int) $employee->branch_id])
            ->values();

        return response()->json(['data' => [
            'branches' => $branches->map(fn ($branch): array => ['id' => $branch->id, 'name' => $branch->name])->all(),
            'lockedBranchId' => $viewAll ? null : ($actor->branch_id === null ? null : (int) $actor->branch_id),
            'officers' => $officers->all(),
            'currentEmployeeId' => $actor->id,
            'canAssignOfficer' => Gate::allows('customers.assign_officer'),
        ]]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->registrar->register($request->validated(), $this->currentEmployee());

        return (new CustomerResource($this->loadForResource($customer)))->response()->setStatusCode(201);
    }

    public function show(int $customer): CustomerResource
    {
        $this->authorizeAny('customers.view');

        return new CustomerResource($this->loadForResource($this->findAccessible($customer, withTrashed: true)));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        $this->registrar->update($customer, $request->validated(), $this->currentEmployee());

        return new CustomerResource($this->loadForResource($customer->refresh()));
    }

    /**
     * GET /customers/{id}/kyc-status — checklist and outstanding items.
     */
    public function kycStatus(Customer $customer): JsonResponse
    {
        $this->authorizeAny('customers.view');
        $this->assertAccessible($customer);

        $items = $this->kyc->checklist($customer);

        return response()->json(['data' => [
            'kycStatus' => $this->kyc->status($customer),
            'items' => $items,
            'outstanding' => collect($items)->filter(fn (array $item): bool => $item['required'] && ! $item['complete'])->pluck('label')->values()->all(),
        ]]);
    }

    /**
     * GET /customers/{id}/overview — loans summary and balances for the profile's Overview tab.
     */
    public function overview(Customer $customer, LoanService $loans, CustomerDebt $debts): JsonResponse
    {
        $this->authorizeAny('customers.view');
        $this->assertAccessible($customer);
        $debt = $debts->summary($customer);

        $customerLoans = $customer->loans()->with('category:id,name')->latest('id')->get();
        $latestLoan = $customerLoans->first();
        $repayable = $customerLoans->filter(fn (Loan $loan): bool => in_array($loan->status, LoanStatus::repayable(), true));

        return response()->json(['data' => [
            'loans' => [
                'total' => $customerLoans->count(),
                'active' => $repayable->count(),
                'inPipeline' => $customerLoans->filter(fn (Loan $loan): bool => in_array($loan->status, LoanStatus::inPipeline(), true))->count(),
                'closed' => $customerLoans->where('status', LoanStatus::Closed)->count(),
                'totalDisbursed' => (float) $customerLoans->filter(fn (Loan $loan): bool => in_array($loan->status, LoanStatus::disbursed(), true))->sum(fn (Loan $loan): float => (float) $loan->amount_approved),
                'outstanding' => (float) $repayable->sum(fn (Loan $loan): float => (float) $loan->remaining_amount),
            ],
            // A customer with no loan in this system can still owe old-system penalties or salary advances.
            'balance' => $latestLoan ? $loans->deductions($latestLoan) : ['remain_loan' => 0, 'salary_advance' => $debt['salary_advance'], 'penalty' => $debt['penalty'], 'loan_fee' => 0, 'total' => round($debt['salary_advance'] + $debt['penalty'], 2), 'remain_cash' => 0],
            'debt' => $debt,
            'recentLoans' => $customerLoans->take(20)->map(fn (Loan $loan): array => [
                'id' => $loan->id,
                'loanNumber' => $loan->loan_number,
                'product' => $loan->category?->name,
                'amountApplied' => (float) $loan->amount_applied,
                'amountApproved' => (float) $loan->amount_approved,
                'totalPayable' => (float) $loan->total_payable,
                'status' => $loan->status?->value,
                'statusLabel' => $loan->status?->label(),
                'statusBadge' => $loan->status?->badge(),
                'withdrawnAt' => $loan->withdrawn_at?->toDateString(),
                'endDate' => $loan->end_date?->toDateString(),
            ])->values()->all(),
            'counts' => [
                'documents' => $customer->documents()->count(),
                'notes' => $customer->notes()->count(),
                'guarantors' => $customer->guarantors()->count(),
                'nextOfKin' => $customer->nextOfKins()->count(),
                'faceScans' => $customer->faceScans()->count(),
            ],
        ]]);
    }

    /**
     * GET /customers/{id}/debt — the customer's debt profile: loan principal, penalty and salary advance as separate debts,
     * their total, and the part carried over from the old system.
     */
    public function debt(Customer $customer, CustomerDebt $debts): JsonResponse
    {
        $this->authorizeAny('customers.view', 'payments.cash', 'payments.suspense', 'loans.apply', 'loans.view');
        $this->assertAccessible($customer);

        return response()->json(['data' => $debts->summary($customer)]);
    }

    /**
     * GET /customers/{id}/timeline — registration, KYC, document, note, face-scan and loan events, newest first.
     */
    public function timeline(Customer $customer): JsonResponse
    {
        $this->authorizeAny('customers.view');
        $this->assertAccessible($customer);

        $events = collect()
            ->push(['type' => 'registered', 'title' => 'Registered', 'description' => $customer->customer_number, 'at' => $customer->created_at, 'byName' => $customer->creator?->full_name])
            ->merge($customer->documents()->with('uploader')->get()->map(fn (CustomerDocument $document): array => ['type' => 'document', 'title' => 'Document uploaded', 'description' => $document->original_name, 'at' => $document->created_at, 'byName' => $document->uploader?->full_name]))
            ->merge($customer->faceScans()->with('scanner')->get()->map(fn (FaceScan $scan): array => ['type' => 'face_scan', 'title' => $scan->status === 'passed' ? 'Face verification passed' : 'Face verification failed', 'description' => "Quality {$scan->quality_score}", 'at' => $scan->scanned_at, 'byName' => $scan->scanner?->full_name]))
            ->merge($customer->notes()->with('author')->get()->map(fn (CustomerNote $note): array => ['type' => 'note', 'title' => 'Note added', 'description' => $note->body, 'at' => $note->created_at, 'byName' => $note->author?->full_name]))
            ->merge($customer->loans()->with('category:id,name')->get()->map(fn (Loan $loan): array => ['type' => 'loan', 'title' => 'Loan '.$loan->loan_number, 'description' => trim(($loan->category?->name ?? '').' · '.$loan->status?->label(), ' ·'), 'at' => $loan->created_at, 'byName' => null]))
            ->merge(AuditLog::query()->with('employee')->where('auditable_type', $customer->getMorphClass())->where('auditable_id', $customer->id)->whereIn('action', ['Customer.approved', 'Customer.rejected', 'Customer.resubmitted', 'Customer.details_updated'])->get()
                ->map(fn (AuditLog $log): array => ['type' => 'approval', 'title' => str($log->action)->after('Customer.')->replace('_', ' ')->ucfirst()->toString(), 'description' => $log->after['reason'] ?? null, 'at' => $log->created_at, 'byName' => $log->employee?->full_name]))
            ->sortByDesc(fn (array $event) => $event['at']?->getTimestamp() ?? 0)
            ->values()
            ->map(fn (array $event): array => ['at' => $event['at']?->toIso8601String()] + $event);

        return response()->json(['data' => $events->all()]);
    }

    /**
     * GET /customers/{id}/audit-trail — audit entries of the customer record.
     */
    public function auditTrail(Customer $customer): JsonResponse
    {
        $this->authorizeAny('customers.view');
        $this->assertAccessible($customer);

        $logs = AuditLog::query()
            ->with('employee')
            ->where('auditable_type', $customer->getMorphClass())
            ->where('auditable_id', $customer->id)
            ->latest('id')
            ->limit(500)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'before' => $log->before,
                'after' => $log->after,
                'employeeId' => $log->employee_id,
                'employeeName' => $log->employee?->full_name,
                'ipAddress' => $log->ip_address,
                'createdAt' => $log->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $logs->all()]);
    }

    /**
     * Rules for the Loans module: allowed loan_category ids, limits, risk level and KYC completion.
     */
    public function eligibility(Customer $customer, CustomerEligibility $eligibility): JsonResponse
    {
        $this->authorizeAny('customers.view', 'loans.view', 'loans.apply');
        $this->assertAccessible($customer);

        return response()->json(['data' => $eligibility->for($customer)]);
    }

    public function mark(Customer $customer): JsonResponse
    {
        $this->authorizeAny('customers.manage');
        $this->assertAccessible($customer);

        $customer->update(['is_marked' => ! $customer->is_marked]);

        return $this->message($customer->is_marked ? 'Customer Marked successfully' : 'Customer Unmarked successfully');
    }

    public function sendSms(Request $request, Customer $customer, SmsGateway $sms): JsonResponse
    {
        $this->authorizeAny('customers.manage', 'messages.use');
        $this->assertAccessible($customer);
        $validated = $request->validate(['message' => ['required', 'string', 'max:480']]);

        try {
            $sms->send($customer->phone, $validated['message']);
        } catch (Throwable $exception) {
            report($exception);

            return $this->message('Message could not be sent', 422, ['errors' => ['message' => ['Message could not be sent']]]);
        }

        SmsLog::create(['company_id' => $customer->company_id, 'customer_id' => $customer->id, 'phone' => $customer->phone, 'message' => $validated['message']]);

        return $this->message('Message sent successfully');
    }

    /**
     * Live Balance modal: deductions against the latest loan.
     */
    public function balance(Customer $customer, LoanService $loans): JsonResponse
    {
        $this->authorizeAny('customers.view');
        $this->assertAccessible($customer);

        $latestLoan = $customer->loans()->latest('id')->first();
        $empty = ['remain_loan' => 0, 'salary_advance' => 0, 'penalty' => 0, 'loan_fee' => 0, 'total' => 0, 'remain_cash' => 0];

        return response()->json(['data' => $latestLoan ? $loans->deductions($latestLoan) : $empty]);
    }

    /**
     * The customer's photo: the capture of the active face scan.
     */
    public function photo(Customer $customer): StreamedResponse
    {
        $this->authorizeAny('customers.view');
        $this->assertAccessible($customer);

        abort_unless($customer->photo_path && Storage::disk(FaceScan::DISK)->exists($customer->photo_path), 404);

        return Storage::disk(FaceScan::DISK)->response($customer->photo_path, null, ['Cache-Control' => 'private, max-age=300']);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorizeAny('customers.manage');
        $this->assertAccessible($customer);

        if ($customer->loans()->whereNotIn('status', [LoanStatus::Rejected->value, LoanStatus::Cancelled->value])->exists()) {
            return $this->message('Customer has loans and cannot be deleted', 422);
        }

        $customer->delete();
        $this->registrar->audit($customer, 'Customer.deleted', ['customer_number' => $customer->customer_number]);

        return $this->message('Customer Deleted successfully');
    }

    private function loadForResource(Customer $customer): Customer
    {
        return $customer->load([...self::RESOURCE_RELATIONS, 'bankDetail', 'nextOfKins', 'guarantors', 'documents']);
    }

    private function findAccessible(int $id, bool $withTrashed = false): Customer
    {
        return $this->scoped(Customer::query())->when($withTrashed, fn (Builder $query) => $query->withTrashed())->findOrFail($id);
    }

    private function assertAccessible(Customer $customer): void
    {
        abort_unless($this->scoped(Customer::query())->whereKey($customer->id)->exists(), 404);
    }
}
