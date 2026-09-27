<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Customers\SaveRegistrationDraftRequest;
use App\Http\Resources\Api\V1\Customers\CustomerRegistrationDraftResource;
use App\Models\Customer;
use App\Models\CustomerRegistrationDraft;
use App\Services\AccessControl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saved registrations (CUSTOMER_MODULE_IMPLEMENTATION.md §3.2, CUSTOMER_MODULE_SPEC.md §8.2–8.3).
 */
class RegistrationDraftController extends ApiController
{
    /**
     * Open drafts created by the user or in their visible branches; the user's own first. Without payloads.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('customers.manage');
        $actor = $this->currentEmployee();

        $drafts = $this->visibleDrafts()
            ->whereNull('submitted_at')
            ->with(['creator:id,first_name,middle_name,last_name', 'branch:id,name'])
            ->orderByRaw('created_by = ? desc', [$actor->id])
            ->latest('updated_at')
            ->latest('id')
            ->get();

        return response()->json(['data' => $drafts->map(fn (CustomerRegistrationDraft $draft): array => (new CustomerRegistrationDraftResource($draft))->withoutPayload()->resolve($request))->all()]);
    }

    public function show(int $draft): CustomerRegistrationDraftResource
    {
        $this->authorizeAny('customers.manage');

        return new CustomerRegistrationDraftResource($this->visibleDrafts()->with(['creator', 'branch'])->findOrFail($draft));
    }

    /**
     * Create (201) or update the user's own open draft (200).
     */
    public function store(SaveRegistrationDraftRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $values = [
            'branch_id' => (int) $validated['branchId'],
            'label' => trim($validated['label']),
            'phone' => filled($validated['phone'] ?? null) ? trim($validated['phone']) : null,
            'step' => (int) ($validated['step'] ?? 0),
            'payload' => (object) $validated['payload'],
        ];

        $draft = $request->draft();
        if ($draft !== null) {
            $draft->update($values);

            return (new CustomerRegistrationDraftResource($draft->load(['creator', 'branch'])))->response()->setStatusCode(200);
        }

        $draft = CustomerRegistrationDraft::create($values + [
            'company_id' => $this->currentEmployee()->company_id,
            'created_by' => $this->currentEmployee()->id,
        ]);

        return (new CustomerRegistrationDraftResource($draft->load(['creator', 'branch'])))->response()->setStatusCode(201);
    }

    public function submitted(Request $request, int $draft): CustomerRegistrationDraftResource
    {
        $this->authorizeAny('customers.manage');
        $record = $this->visibleDrafts()->findOrFail($draft);
        abort_unless((int) $record->created_by === $this->currentEmployee()->id, 403, 'This draft belongs to another officer.');
        abort_if($record->submitted_at !== null, 409, 'This registration has already been submitted.');

        $validated = $request->validate([
            'customerId' => ['required', 'integer', Rule::exists('customers', 'id')->where('company_id', $this->currentEmployee()->company_id)],
        ]);
        abort_unless($this->scoped(Customer::query())->whereKey($validated['customerId'])->exists(), 403, 'You do not have access to this customer.');

        $record->update(['customer_id' => (int) $validated['customerId'], 'submitted_at' => now()]);

        return new CustomerRegistrationDraftResource($record->load(['creator', 'branch']));
    }

    public function destroy(int $draft): JsonResponse
    {
        $this->authorizeAny('customers.manage');
        $record = $this->visibleDrafts()->findOrFail($draft);
        abort_unless((int) $record->created_by === $this->currentEmployee()->id, 403, 'This draft belongs to another officer.');

        $record->delete();

        return $this->message('Draft discarded.');
    }

    /**
     * @return Builder<CustomerRegistrationDraft>
     */
    private function visibleDrafts(): Builder
    {
        $actor = $this->currentEmployee();
        $branchIds = app(AccessControl::class)->branchIds($actor);

        return CustomerRegistrationDraft::query()
            ->where('company_id', $actor->company_id)
            ->when($branchIds !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('created_by', $actor->id)->orWhereIn('branch_id', $branchIds)));
    }
}
