<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Http\Requests\Api\Hrm\StaffDeductionRequest;
use App\Models\StaffDeduction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRM → Staff Deduction (live admin/staf_deduction). One instalment is withheld on every payroll.
 */
class DeductionController extends HrmController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage');

        $query = $this->scoped(StaffDeduction::query())->with(['branch', 'employee']);
        $deductions = $this->applyFilters($query, $request, 'created_at')->latest('id')->get();

        return response()->json(['data' => $deductions->map(fn (StaffDeduction $deduction): array => [
            'id' => $deduction->id,
            'branch' => $deduction->branch?->name,
            'employee_id' => $deduction->employee_id,
            'employee' => $deduction->employee?->full_name,
            'amount' => (float) $deduction->amount,
            'instalments' => $deduction->instalments,
            'instalment_amount' => (float) $deduction->instalment_amount,
            'paid_amount' => (float) $deduction->paid_amount,
            'description' => $deduction->description,
            'status' => $deduction->status,
            'created_at' => $deduction->created_at?->toDateString(),
        ])]);
    }

    public function store(StaffDeductionRequest $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage');
        $this->assertBranchAccessible($request->integer('blanch_id'));

        $amount = (float) $request->input('amount');
        $instalments = $request->integer('instalment');

        StaffDeduction::create([
            'company_id' => $this->companyId(),
            'branch_id' => $request->integer('blanch_id'),
            'employee_id' => $request->integer('empl_id'),
            'amount' => $amount,
            'instalments' => $instalments,
            'instalment_amount' => round($amount / $instalments, 2),
            'description' => $request->input('description'),
            'status' => 'active',
        ]);

        return $this->message('Deduction Saved successfully', 201);
    }
}
