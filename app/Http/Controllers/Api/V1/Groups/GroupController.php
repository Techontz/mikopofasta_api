<?php

namespace App\Http\Controllers\Api\V1\Groups;

use App\Enums\LoanStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Group;
use App\Models\Loan;
use App\Services\Reports\LoanBalances;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Group → All groups (admin/group) and the group customer list (admin/view_customer_group/{id}).
 * Gated by the live GROUP privilege (groups.view / groups.manage); the select options stay available to customer
 * registration and loan application.
 */
class GroupController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->authorizeAny('groups.view', 'groups.manage');

        $groups = Group::where('company_id', $this->currentEmployee()->company_id)->withCount('customers')->orderBy('id')->get();

        return response()->json(['data' => $groups->map(fn (Group $group): array => ['id' => $group->id, 'name' => $group->name, 'customers_count' => $group->customers_count])]);
    }

    public function options(): JsonResponse
    {
        $this->authorizeAny('groups.view', 'customers.view', 'loans.apply');

        $groups = Group::where('company_id', $this->currentEmployee()->company_id)->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => $groups->map(fn (Group $group): array => ['value' => (string) $group->id, 'label' => $group->name])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAny('groups.manage');
        $data = $request->validate(['group_name' => ['required', 'string', 'max:255']]);

        $group = Group::create(['company_id' => $this->currentEmployee()->company_id, 'name' => $data['group_name']]);

        return $this->message('Group Registered successfully', 201, ['data' => ['id' => $group->id]]);
    }

    public function update(Request $request, Group $group): JsonResponse
    {
        $this->authorizeAny('groups.manage');
        $data = $request->validate(['group_name' => ['required', 'string', 'max:255']]);

        $group->update(['name' => $data['group_name']]);

        return $this->message('Group Updated successfully');
    }

    public function destroy(Group $group): JsonResponse
    {
        $this->authorizeAny('groups.manage');

        $group->delete();

        return $this->message('Group Deleted successfully');
    }

    /**
     * Customer List / {group}: loans of group members or loans taken as the group, with the live Filter (branch incl. ALL).
     * Paid / Remain come from {@see LoanBalances} (reversed repayments ignored): Remain = outstanding principal + penalty +
     * interest + insurance for disbursed loans, the same number as LoanService::outstanding() on the loan and teller pages;
     * loans not yet disbursed show their total payable.
     */
    public function show(Request $request, Group $group): JsonResponse
    {
        $this->authorizeAny('groups.view', 'groups.manage');

        $loans = LoanBalances::join($this->applyFilters($this->scoped(Loan::query()), $request))
            ->where(fn (Builder $query) => $query
                ->where('loans.group_id', $group->id)
                ->orWhereHas('customer', fn (Builder $customers) => $customers->where('group_id', $group->id)))
            ->with(['branch:id,name', 'customer:id,first_name,middle_name,last_name,phone,gender', 'writeOff'])
            ->latest('loans.id')
            ->get();

        return response()->json([
            'group' => ['id' => $group->id, 'name' => $group->name],
            'data' => $loans->map(fn (Loan $loan): array => [
                'id' => $loan->id,
                'customer_id' => $loan->customer_id,
                'branch' => strtoupper((string) $loan->branch?->name),
                'customer_name' => $loan->customer?->full_name,
                'phone' => $loan->customer?->phone,
                'gender' => $loan->customer?->gender,
                'total_loan' => (float) $loan->total_payable,
                'paid_amount' => round((float) $loan->paid_total, 2),
                'remain' => in_array($loan->status, LoanStatus::disbursed(), true) ? (float) $loan->out_total : (float) $loan->total_payable,
                'restoration' => (float) $loan->restoration,
                'write_off' => (float) ($loan->writeOff?->amount ?? 0),
                'status' => $loan->status?->label(),
                'status_badge' => $loan->status?->badge(),
            ])->values(),
        ]);
    }
}
