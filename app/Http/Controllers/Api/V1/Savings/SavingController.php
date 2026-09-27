<?php

namespace App\Http\Controllers\Api\V1\Savings;

use App\Enums\Account;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Savings\SavingWithdrawalRequest;
use App\Http\Resources\Api\V1\Savings\SavingResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Saving;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Ledger;
use App\Services\SavingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Insurance (insurance savings): customer deposit & withdrawal (live admin/search_customer_saving),
 * today deposits, withdrawals and balances.
 */
class SavingController extends ApiController
{
    public function __construct(
        private readonly SavingService $service,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Customer saving page: profile, total saving and statement with running balance.
     */
    public function show(Customer $customer): JsonResponse
    {
        $this->authorizeAny('savings.manage');
        $this->assertBranchAccessible($customer->branch_id);

        $running = 0.0;
        $statement = $customer->savings()->orderBy('transaction_date')->orderBy('id')->get()->map(function (Saving $saving) use (&$running): array {
            if ($saving->reversed_at === null) {
                $running += $saving->type === 'deposit' ? (float) $saving->amount : -(float) $saving->amount;
            }

            return (new SavingResource($saving))->resolve() + ['balance' => round($running, 2)];
        });

        return response()->json(['data' => [
            'customer' => [
                'id' => $customer->id,
                'full_name' => $customer->full_name,
                'phone' => $customer->phone,
                'photo_url' => $customer->photo_url,
                'branch' => $customer->branch?->name,
            ],
            'total_saving' => $this->service->balance($customer),
            'statement' => $statement->values(),
        ]]);
    }

    public function deposit(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeAny('savings.manage');
        $this->assertBranchAccessible($customer->branch_id);

        $request->validate(['dep_sav' => ['required', 'numeric', 'min:1']], ['dep_sav.required' => 'Please enter amount']);

        $this->service->deposit($customer, $request->float('dep_sav'), $this->currentEmployee());

        return $this->message('Saving Deposit successfully', 201);
    }

    public function withdraw(SavingWithdrawalRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeAny('savings.manage');
        $this->assertBranchAccessible($customer->branch_id);

        $this->service->withdraw($customer, $request->float('with_sav'), $request->string('action')->toString(), $this->currentEmployee());

        return $this->message('Saving Withdrawal successfully', 201);
    }

    public function reverse(Request $request, Saving $saving): JsonResponse
    {
        $this->authorizeAny('savings.manage');
        $this->authorizeAny('accounting.reverse');
        $this->assertBranchAccessible($saving->branch_id);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Please enter the reason for reversal']);
        app(SegregationOfDuties::class)->assertCanReverseRecord($saving->employee_id, $this->currentEmployee());

        $this->service->reverse($saving, $data['reason'], $this->currentEmployee());

        return $this->message('Transaction Reversed successfully');
    }

    /**
     * "Today saving Deposit" — today, or the filtered branch and dates.
     */
    public function deposits(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('savings.manage');

        $query = $this->transactions($request, 'deposit');
        if (! $request->filled('from') && ! $request->filled('to')) {
            $query->whereDate('transaction_date', today());
        }

        return SavingResource::collection($query->get());
    }

    /**
     * "Saving withdrawal" — all withdrawals (tabs: All / Saving Taken / Saving clear loan on the client).
     */
    public function withdrawals(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('savings.manage');

        return SavingResource::collection($this->transactions($request, 'withdrawal')->get());
    }

    /**
     * "Saving Deposit balance" — balance per customer (filter: branch).
     */
    public function balances(Request $request): JsonResponse
    {
        $this->authorizeAny('savings.manage');

        $balances = $this->applyFilters($this->scoped(Saving::query()), $request)
            ->whereNull('reversed_at')
            ->selectRaw("customer_id, branch_id, SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END) as balance")
            ->groupBy('customer_id', 'branch_id')
            ->with(['customer', 'branch'])
            ->get();

        return response()->json(['data' => $balances->map(fn (Saving $row): array => [
            'customer_id' => $row->customer_id,
            'customer' => $row->customer?->full_name,
            'branch' => $row->branch?->name,
            'amount' => round((float) $row->getAttribute('balance'), 2),
        ])->values()]);
    }

    /**
     * "Saving Deposit Balance" modal — HQ saving account balance of every visible branch.
     */
    public function branchBalances(): JsonResponse
    {
        $this->authorizeAny('savings.manage');

        $companyId = $this->currentEmployee()->company_id;

        return response()->json(['data' => $this->visibleBranches()->map(fn (Branch $branch): array => [
            'branch_id' => $branch->id,
            'branch' => $branch->name,
            'amount' => $this->ledger->balance($companyId, Account::HqSaving, $branch),
        ])->values()]);
    }

    /**
     * @return Builder<Saving>
     */
    private function transactions(Request $request, string $type): Builder
    {
        return $this->applyFilters($this->scoped(Saving::query()), $request, 'transaction_date')
            ->where('type', $type)
            ->with(['customer', 'branch'])
            ->latest('transaction_date')
            ->latest('id');
    }
}
