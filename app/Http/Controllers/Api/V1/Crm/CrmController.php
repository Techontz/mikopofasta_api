<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Enums\LoanStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Crm\InteractionResource;
use App\Http\Resources\Api\V1\Crm\TicketResource;
use App\Models\CrmInteraction;
use App\Models\CrmTicket;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\SmsLog;
use App\Services\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRM (handwritten notes "CRM: Customer Management System"): dashboard counters, option lists,
 * the customer 360° view ("kuangalia report za wateja") and the staff activity report.
 */
class CrmController extends ApiController
{
    public function summary(): JsonResponse
    {
        $this->authorizeAny('crm.use');

        $today = now()->toDateString();
        $me = $this->currentEmployee()->id;
        $interactions = fn () => $this->scoped(CrmInteraction::query());
        $pendingFollowUps = fn () => $interactions()->whereNotNull('follow_up_date')->whereNull('follow_up_done_at');

        return response()->json(['data' => [
            'calls_today' => $interactions()->where('type', 'call')->whereDate('created_at', $today)->count(),
            'incoming_today' => $interactions()->where('type', 'call')->where('direction', 'incoming')->whereDate('created_at', $today)->count(),
            'sms_today' => $interactions()->where('type', 'sms')->whereDate('created_at', $today)->count(),
            'my_follow_ups_due' => $pendingFollowUps()->where('employee_id', $me)->whereDate('follow_up_date', '<=', $today)->count(),
            'follow_ups_due' => $pendingFollowUps()->whereDate('follow_up_date', $today)->count(),
            'follow_ups_overdue' => $pendingFollowUps()->whereDate('follow_up_date', '<', $today)->count(),
            'open_tickets' => $this->scoped(CrmTicket::query())->whereIn('status', ['open', 'in_progress'])->count(),
        ]]);
    }

    public function options(): JsonResponse
    {
        $this->authorizeAny('crm.use');

        $pairs = fn (array $values): array => collect($values)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all();

        return response()->json(['data' => [
            'types' => $pairs(CrmInteraction::TYPES),
            'directions' => $pairs(CrmInteraction::DIRECTIONS),
            'outcomes' => $pairs(CrmInteraction::OUTCOMES),
            'categories' => $pairs(CrmTicket::CATEGORIES),
            'channels' => $pairs(CrmTicket::CHANNELS),
            'priorities' => $pairs(CrmTicket::PRIORITIES),
            'statuses' => $pairs(CrmTicket::STATUSES),
            'customer_statuses' => [['value' => 'all', 'label' => 'ALL'], ...$pairs(Customer::STATUSES)],
        ]]);
    }

    /**
     * Customer 360° view: profile, loans with outstanding balances, recent repayments, CRM timeline,
     * reports/complaints and SMS history.
     */
    public function customer(Customer $customer, LoanService $loans): JsonResponse
    {
        $this->authorizeAny('crm.use');
        abort_unless($customer->company_id === $this->currentEmployee()->company_id, 404);
        $this->assertBranchAccessible((int) $customer->branch_id);

        $customer->load(['branch', 'employee']);

        $loanRows = Loan::where('customer_id', $customer->id)->latest('id')->get()->map(function (Loan $loan) use ($loans): array {
            $status = $loan->status instanceof LoanStatus ? $loan->status : LoanStatus::tryFrom((string) $loan->status);
            $open = in_array($status, LoanStatus::repayable(), true);

            return [
                'id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'amount' => (float) ($loan->amount_approved > 0 ? $loan->amount_approved : $loan->amount_applied),
                'total_payable' => (float) $loan->total_payable,
                'outstanding' => $open ? $loans->outstanding($loan)['total'] : 0.0,
                'status' => $status?->value ?? (string) $loan->status,
                'status_label' => $status?->label() ?? strtoupper((string) $loan->status),
                'status_badge' => $status?->badge() ?? 'default',
                'end_date' => $loan->end_date?->toDateString(),
            ];
        });

        $payments = LoanTransaction::where('customer_id', $customer->id)->where('type', 'deposit')->whereNull('reversed_at')
            ->latest('transaction_date')->latest('id')->limit(10)->get()
            ->map(fn (LoanTransaction $transaction): array => [
                'id' => $transaction->id,
                'date' => substr((string) $transaction->transaction_date, 0, 10),
                'amount' => (float) $transaction->amount,
                'method' => $transaction->method,
            ]);

        return response()->json(['data' => [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->full_name,
                'code' => $customer->customer_code,
                'phone' => $customer->phone,
                'gender' => $customer->gender,
                'status' => $customer->status,
                'status_label' => $customer->status_label,
                'branch' => $customer->branch?->name,
                'officer' => $customer->employee?->full_name,
                'registered_at' => $customer->created_at?->toDateString(),
            ],
            'totals' => [
                'loans' => $loanRows->count(),
                'outstanding' => round($loanRows->sum('outstanding'), 2),
                'paid' => (float) LoanTransaction::where('customer_id', $customer->id)->where('type', 'deposit')->whereNull('reversed_at')->sum('amount'),
            ],
            'loans' => $loanRows->values(),
            'payments' => $payments->values(),
            'timeline' => InteractionResource::collection(
                CrmInteraction::where('customer_id', $customer->id)->with(['employee'])->latest('id')->limit(200)->get()
            ),
            'tickets' => TicketResource::collection(
                CrmTicket::where('customer_id', $customer->id)->with(['employee', 'assignee'])->latest('id')->get()
            ),
            'sms' => SmsLog::where('customer_id', $customer->id)->latest('id')->limit(50)->get()
                ->map(fn (SmsLog $log): array => ['id' => $log->id, 'phone' => $log->phone, 'message' => $log->message, 'created_at' => $log->created_at?->format('Y-m-d H:i')]),
        ]]);
    }

    /**
     * Staff CRM activity per employee for the live branch + from/to filter.
     */
    public function report(Request $request): JsonResponse
    {
        $this->authorizeAny('crm.use');

        $interactions = $this->applyFilters($this->scoped(CrmInteraction::query()), $request, 'crm_interactions.created_at')
            ->selectRaw("employee_id,
                SUM(type = 'call') as calls,
                SUM(type = 'call' AND direction = 'incoming') as incoming,
                SUM(type = 'call' AND direction = 'outgoing') as outgoing,
                SUM(type = 'call' AND outcome = 'promised_to_pay') as promised,
                SUM(type = 'call' AND outcome IN ('no_answer', 'not_reachable', 'busy')) as unreached,
                SUM(type = 'sms') as sms,
                SUM(follow_up_done_at IS NOT NULL) as follow_ups_done,
                SUM(follow_up_date IS NOT NULL AND follow_up_done_at IS NULL AND follow_up_date < CURDATE()) as follow_ups_overdue")
            ->groupBy('employee_id')->toBase()->get()->keyBy('employee_id');

        $tickets = $this->applyFilters($this->scoped(CrmTicket::query()), $request, 'crm_tickets.created_at')
            ->selectRaw("employee_id, COUNT(*) as opened, SUM(status IN ('resolved', 'closed')) as resolved")
            ->groupBy('employee_id')->toBase()->get()->keyBy('employee_id');

        $employeeIds = $interactions->keys()->merge($tickets->keys())->filter()->unique();
        $employees = Employee::with('branch')->whereIn('id', $employeeIds)->get()->keyBy('id');

        $rows = $employeeIds->map(function (int|string $id) use ($interactions, $tickets, $employees): array {
            $activity = $interactions->get($id);
            $ticket = $tickets->get($id);

            return [
                'employee_id' => (int) $id,
                'employee' => $employees->get($id)?->full_name ?? '-',
                'branch' => $employees->get($id)?->branch?->name,
                'calls' => (int) ($activity->calls ?? 0),
                'incoming' => (int) ($activity->incoming ?? 0),
                'outgoing' => (int) ($activity->outgoing ?? 0),
                'promised' => (int) ($activity->promised ?? 0),
                'unreached' => (int) ($activity->unreached ?? 0),
                'sms' => (int) ($activity->sms ?? 0),
                'follow_ups_done' => (int) ($activity->follow_ups_done ?? 0),
                'follow_ups_overdue' => (int) ($activity->follow_ups_overdue ?? 0),
                'tickets_opened' => (int) ($ticket->opened ?? 0),
                'tickets_resolved' => (int) ($ticket->resolved ?? 0),
            ];
        })->sortByDesc('calls')->values();

        return response()->json(['data' => $rows]);
    }
}
