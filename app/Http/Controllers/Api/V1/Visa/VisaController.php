<?php

namespace App\Http\Controllers\Api\V1\Visa;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Visa\VisaCustomerResource;
use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * VISA — customer bank account & card password list (live admin/bank_password).
 */
class VisaController extends ApiController
{
    /**
     * Employed customers (work status "ent") and customers with a bank account (filter: branch).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('visa.manage');

        $customers = $this->applyFilters($this->scoped(Customer::query()), $request)
            ->where(fn (Builder $query) => $query->where('work_status', 'ent')->orWhereNotNull('bank_account_name'))
            ->with('branch')
            ->orderBy('first_name')
            ->get();

        return VisaCustomerResource::collection($customers);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeAny('visa.manage');
        $this->assertBranchAccessible($customer->branch_id);

        $data = $request->validate([
            'ac_name' => ['nullable', 'string', 'max:255'],
            'ac_password' => ['nullable', 'string', 'max:255'],
        ]);

        $before = ['bank_account_name' => $customer->bank_account_name, 'bank_password_set' => filled($customer->bank_password)];

        $customer->update([
            'bank_account_name' => $data['ac_name'] ?? null,
            'bank_password' => $data['ac_password'] ?? null,
        ]);

        AuditLog::create([
            'company_id' => $customer->company_id,
            'employee_id' => $this->currentEmployee()->id,
            'action' => 'Customer.visa_updated',
            'auditable_type' => $customer->getMorphClass(),
            'auditable_id' => $customer->id,
            'before' => $before,
            'after' => ['bank_account_name' => $customer->bank_account_name, 'bank_password_set' => filled($customer->bank_password)],
            'ip_address' => $request->ip(),
        ]);

        return $this->message('Account Updated successfully', 200, ['data' => new VisaCustomerResource($customer->load('branch'))]);
    }
}
