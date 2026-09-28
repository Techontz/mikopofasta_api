<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Http\Requests\Api\Hrm\PerformanceReviewRequest;
use App\Models\Employee;
use App\Models\StaffPerformanceReview;
use App\Services\Hrm\PerformanceMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRM → Performance: officer KPIs vs goals and manager reviews (STAFF COMMISSION §15).
 */
class PerformanceController extends HrmController
{
    public function index(Request $request, PerformanceMetrics $metrics): JsonResponse
    {
        $this->authorizeAny('hrm.manage');

        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString()) : CarbonImmutable::now()->startOfMonth();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString()) : CarbonImmutable::today();

        $employees = $this->applyFilters($this->scoped(Employee::query())->staff()->where('status', 'active'), $request)
            ->where(fn ($query) => $query->whereHas('role', fn ($role) => $role->whereIn('key', ['loan_officer', 'branch_manager']))
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('loans')->whereColumn('loans.employee_id', 'employees.id'))
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('customers')->whereColumn('customers.employee_id', 'employees.id')))
            ->with(['branch', 'role'])
            ->orderBy('first_name')
            ->get();

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $metrics->forEmployees($employees, $from, $to),
        ]]);
    }

    public function reviews(Request $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage');

        $reviews = StaffPerformanceReview::where('company_id', $this->companyId())
            ->whereHas('employee', fn ($query) => $this->scoped($query))
            ->when($request->filled('employee_id'), fn ($query) => $query->where('employee_id', $request->integer('employee_id')))
            ->with(['employee.branch', 'reviewer'])
            ->latest('period')
            ->latest('id')
            ->get();

        return response()->json(['data' => $reviews->map(fn (StaffPerformanceReview $review): array => [
            'id' => $review->id,
            'employee_id' => $review->employee_id,
            'employee' => $review->employee?->full_name,
            'branch' => $review->employee?->branch?->name,
            'period' => $review->period->format('Y-m'),
            'targets' => $review->targets,
            'discipline' => $review->discipline,
            'rating' => $review->rating,
            'remarks' => $review->remarks,
            'reviewed_by' => $review->reviewer?->full_name,
            'created_at' => $review->created_at?->toDateString(),
        ])]);
    }

    public function storeReview(PerformanceReviewRequest $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage');

        $employee = Employee::staff()->findOrFail($request->integer('empl_id'));
        $this->ensureVisible($employee);

        StaffPerformanceReview::create([
            'company_id' => $this->companyId(),
            'employee_id' => $employee->id,
            'period' => $request->string('period')->toString().'-01',
            'targets' => $request->input('targets'),
            'discipline' => $request->input('discipline'),
            'rating' => $request->integer('rating'),
            'remarks' => $request->input('remarks'),
            'reviewed_by' => $this->currentEmployee()->id,
        ]);

        return $this->message('Performance Review Saved successfully', 201);
    }
}
