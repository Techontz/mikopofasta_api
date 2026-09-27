<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Customer;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer statement data (consumed by Reports → Customer statement): each transaction with its
 * Principal / Penalty / Interest / Insurance split, running remaining debit and receipt reference. Reversed repayments
 * stay listed (flagged `reversed`) but count in neither the remaining debit nor the totals.
 */
class StatementController extends ApiController
{
    public function __invoke(Request $request, Customer $customer, PaymentService $payments): JsonResponse
    {
        $this->authorizeAny('reports.view', 'payments.cash', 'payments.verify', 'payments.suspense');
        $this->assertBranchAccessible((int) $customer->branch_id);

        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $rows = $payments->statement($customer, $request->input('from'), $request->input('to'));

        return response()->json([
            'data' => $rows,
            'customer' => [
                'id' => $customer->id,
                'full_name' => $customer->full_name,
                'customer_code' => $customer->customer_code,
                'phone' => $customer->phone,
                'branch' => $customer->branch?->name,
            ],
            'totals' => collect(['deposit', 'withdrawal', 'principal', 'penalty', 'interest', 'insurance'])
                ->mapWithKeys(fn (string $column): array => [$column => round((float) $rows->where('reversed', false)->sum($column), 2)])
                ->all(),
        ]);
    }
}
