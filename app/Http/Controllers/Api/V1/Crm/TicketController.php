<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Crm\TicketRequest;
use App\Http\Requests\Api\Crm\UpdateTicketRequest;
use App\Http\Resources\Api\V1\Crm\TicketResource;
use App\Models\CrmTicket;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CRM customer reports / complaints received from customers and followed until resolved.
 */
class TicketController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('crm.use');

        $tickets = $this->applyFilters($this->scoped(CrmTicket::query()), $request, 'crm_tickets.created_at')
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($query) => $request->input('status') === 'pending'
                ? $query->whereIn('status', ['open', 'in_progress'])
                : $query->where('status', $request->string('status')->toString()))
            ->when($request->boolean('mine'), fn ($query) => $query->where(fn ($inner) => $inner
                ->where('assigned_to', $this->currentEmployee()->id)->orWhere('employee_id', $this->currentEmployee()->id)))
            ->with(['customer', 'branch', 'employee', 'assignee'])
            ->latest('id')
            ->limit(2000)
            ->get();

        return TicketResource::collection($tickets);
    }

    public function store(TicketRequest $request): JsonResponse
    {
        $this->authorizeAny('crm.use');

        $customer = Customer::where('company_id', $this->currentEmployee()->company_id)->findOrFail($request->integer('customer_id'));
        $this->assertBranchAccessible((int) $customer->branch_id);

        $ticket = CrmTicket::create([
            'company_id' => $customer->company_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'employee_id' => $this->currentEmployee()->id,
            'assigned_to' => $request->input('assigned_to'),
            'category' => $request->string('category')->toString(),
            'channel' => $request->string('channel')->toString(),
            'priority' => $request->string('priority')->toString(),
            'subject' => $request->string('subject')->toString(),
            'description' => $request->string('description')->toString(),
        ]);

        return $this->message('Customer report registered successfully', 201, ['data' => new TicketResource($ticket->refresh()->load(['customer', 'branch', 'employee', 'assignee']))]);
    }

    public function show(CrmTicket $ticket): TicketResource
    {
        $this->authorizeTicket($ticket);

        return new TicketResource($ticket->load(['customer', 'branch', 'employee', 'assignee', 'resolver']));
    }

    public function update(UpdateTicketRequest $request, CrmTicket $ticket): JsonResponse
    {
        $this->authorizeTicket($ticket);

        $status = $request->string('status')->toString();
        $finished = in_array($status, ['resolved', 'closed'], true);

        $ticket->update([
            'status' => $status,
            'priority' => $request->string('priority')->toString(),
            'assigned_to' => $request->input('assigned_to'),
            'resolution' => $request->input('resolution'),
            'resolved_at' => $finished ? ($ticket->resolved_at ?? now()) : null,
            'resolved_by' => $finished ? ($ticket->resolved_by ?? $this->currentEmployee()->id) : null,
        ]);

        return $this->message('Customer report updated successfully', 200, ['data' => new TicketResource($ticket->load(['customer', 'branch', 'employee', 'assignee', 'resolver']))]);
    }

    private function authorizeTicket(CrmTicket $ticket): void
    {
        $this->authorizeAny('crm.use');
        abort_unless($ticket->company_id === $this->currentEmployee()->company_id, 404);
        $this->assertBranchAccessible((int) $ticket->branch_id);
    }
}
