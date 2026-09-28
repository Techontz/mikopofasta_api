<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Crm\BulkSmsRequest;
use App\Http\Requests\Api\Crm\CallRequest;
use App\Http\Requests\Api\Crm\CompleteFollowUpRequest;
use App\Http\Requests\Api\Crm\SmsRequest;
use App\Http\Resources\Api\V1\Crm\InteractionResource;
use App\Models\CrmInteraction;
use App\Models\Customer;
use App\Services\Crm\CustomerMessenger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * CRM calls (received and made by staff), SMS to customers and follow-up reminders.
 */
class InteractionController extends ApiController
{
    /**
     * Call & SMS log with the live branch + from/to filter and optional type / customer filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('crm.use');

        $interactions = $this->applyFilters($this->scoped(CrmInteraction::query()), $request, 'crm_interactions.created_at')
            ->when($request->filled('type') && $request->input('type') !== 'all', fn ($query) => $query->where('type', $request->string('type')->toString()))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')))
            ->when($request->boolean('mine'), fn ($query) => $query->where('employee_id', $this->currentEmployee()->id))
            ->with(['customer', 'branch', 'employee'])
            ->latest('id')
            ->limit(2000)
            ->get();

        return InteractionResource::collection($interactions);
    }

    public function storeCall(CallRequest $request): JsonResponse
    {
        $this->authorizeAny('crm.use');
        $customer = $this->accessibleCustomer($request->integer('customer_id'));

        $interaction = CrmInteraction::create([
            'company_id' => $customer->company_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'employee_id' => $this->currentEmployee()->id,
            'type' => 'call',
            'direction' => $request->string('direction')->toString(),
            'phone' => $request->filled('phone') ? $request->string('phone')->toString() : $customer->phone,
            'outcome' => $request->string('outcome')->toString(),
            'notes' => $request->input('notes'),
            'follow_up_date' => $request->input('follow_up_date'),
        ]);

        return $this->message('Call recorded successfully', 201, ['data' => new InteractionResource($interaction->load(['customer', 'branch', 'employee']))]);
    }

    public function storeSms(SmsRequest $request, CustomerMessenger $messenger): JsonResponse
    {
        $this->authorizeAny('crm.use');
        $customer = $this->accessibleCustomer($request->integer('customer_id'));

        $interaction = $messenger->send(
            $customer,
            $request->string('message')->toString(),
            $this->currentEmployee(),
            $request->filled('follow_up_date') ? CarbonImmutable::parse($request->string('follow_up_date')->toString()) : null,
        );

        return $this->message('Message sent successfully', 201, ['data' => new InteractionResource($interaction->load(['customer', 'branch', 'employee']))]);
    }

    /**
     * Inferred: bulk SMS goes to every customer of the chosen branch ("all" = every visible branch)
     * with the chosen status; capped at 1000 recipients per send.
     */
    public function bulkSms(BulkSmsRequest $request, CustomerMessenger $messenger): JsonResponse
    {
        $this->authorizeAny('crm.use');

        $branch = $request->string('branch_id')->toString();
        if ($branch !== 'all') {
            $this->assertBranchAccessible((int) $branch);
        }

        $customers = $this->scoped(Customer::query())
            ->when($branch !== 'all', fn ($query) => $query->where('branch_id', (int) $branch))
            ->when($request->input('customer_status') !== 'all', fn ($query) => $query->where('status', $request->string('customer_status')->toString()))
            ->whereNotNull('phone')
            ->limit(1001)
            ->get();

        if ($customers->isEmpty()) {
            throw ValidationException::withMessages(['customer_status' => 'No customers match the selected branch and status.']);
        }
        if ($customers->count() > 1000) {
            throw ValidationException::withMessages(['branch_id' => 'Too many recipients (more than 1000). Narrow the branch or status.']);
        }

        $message = $request->string('message')->toString();
        foreach ($customers as $customer) {
            $messenger->send($customer, $message, $this->currentEmployee());
        }

        return $this->message("Message sent successfully to {$customers->count()} customers", 201, ['count' => $customers->count()]);
    }

    /**
     * Follow-up reminders: status due (today and overdue), overdue, upcoming, done or all; mine=1 limits to the officer.
     */
    public function followUps(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('crm.use');

        $today = now()->toDateString();
        $status = $request->input('status', 'due');

        $followUps = $this->applyFilters($this->scoped(CrmInteraction::query()), $request, 'crm_interactions.follow_up_date')
            ->whereNotNull('follow_up_date')
            ->when($request->boolean('mine'), fn ($query) => $query->where('employee_id', $this->currentEmployee()->id))
            ->when($status === 'due', fn ($query) => $query->whereNull('follow_up_done_at')->whereDate('follow_up_date', '<=', $today))
            ->when($status === 'overdue', fn ($query) => $query->whereNull('follow_up_done_at')->whereDate('follow_up_date', '<', $today))
            ->when($status === 'upcoming', fn ($query) => $query->whereNull('follow_up_done_at')->whereDate('follow_up_date', '>', $today))
            ->when($status === 'done', fn ($query) => $query->whereNotNull('follow_up_done_at'))
            ->with(['customer', 'branch', 'employee'])
            ->orderBy('follow_up_date')
            ->limit(2000)
            ->get();

        return InteractionResource::collection($followUps);
    }

    public function completeFollowUp(CompleteFollowUpRequest $request, CrmInteraction $interaction): JsonResponse
    {
        $this->authorizeAny('crm.use');
        abort_unless($interaction->company_id === $this->currentEmployee()->company_id, 404);
        $this->assertBranchAccessible((int) $interaction->branch_id);

        if ($interaction->follow_up_date === null || $interaction->follow_up_done_at !== null) {
            throw ValidationException::withMessages(['follow_up_notes' => 'This follow-up is not pending.']);
        }

        $interaction->update([
            'follow_up_done_at' => now(),
            'follow_up_done_by' => $this->currentEmployee()->id,
            'follow_up_notes' => $request->input('follow_up_notes'),
        ]);

        return $this->message('Follow-up completed successfully');
    }

    private function accessibleCustomer(int $customerId): Customer
    {
        $customer = Customer::where('company_id', $this->currentEmployee()->company_id)->findOrFail($customerId);
        $this->assertBranchAccessible((int) $customer->branch_id);

        return $customer;
    }
}
