<?php

namespace App\Http\Controllers\Api\V1\Goals;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Goals\GoalRequest;
use App\Http\Resources\Api\V1\Goals\GoalResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Goal;
use App\Models\LoanTransaction;
use App\Models\Zone;
use App\Services\AccessControl;
use App\Services\Goals\GoalProgress;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Goals (handwritten notes "GOALS"): admin sets goals for almost everyone (e.g. new customers after a period)
 * and the system generates progress reports with graphs.
 */
class GoalController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('goals.view', 'goals.manage');
        $today = now()->toDateString();

        $goals = $this->visibleGoals()
            ->when($request->input('status', 'active') === 'active', fn (Builder $query) => $query->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today))
            ->when($request->input('status') === 'upcoming', fn (Builder $query) => $query->whereDate('start_date', '>', $today))
            ->when($request->input('status') === 'past', fn (Builder $query) => $query->whereDate('end_date', '<', $today))
            ->when($request->filled('metric') && $request->input('metric') !== 'all', fn (Builder $query) => $query->where('metric', $request->string('metric')->toString()))
            ->when($request->filled('scope_type') && $request->input('scope_type') !== 'all', fn (Builder $query) => $query->where('scope_type', $request->string('scope_type')->toString()))
            ->when($request->filled('from'), fn (Builder $query) => $query->whereDate('end_date', '>=', $request->string('from')->toString()))
            ->when($request->filled('to'), fn (Builder $query) => $query->whereDate('start_date', '<=', $request->string('to')->toString()))
            ->with(['branch', 'zone', 'employee', 'creator'])
            ->orderByDesc('start_date')
            ->orderBy('id')
            ->get();

        return GoalResource::collection($goals);
    }

    public function options(): JsonResponse
    {
        $this->authorizeAny('goals.view', 'goals.manage');

        $pairs = fn (array $values): array => collect($values)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all();
        $branchIds = app(AccessControl::class)->branchIds($this->currentEmployee());

        return response()->json(['data' => [
            'metrics' => $pairs(Goal::METRICS),
            'scopes' => $pairs(Goal::SCOPES),
            'periods' => $pairs(Goal::PERIODS),
            'zones' => Zone::where('company_id', $this->currentEmployee()->company_id)
                ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('id', Branch::whereIn('id', $branchIds)->whereNotNull('zone_id')->select('zone_id')))
                ->orderBy('name')->get()->map(fn (Zone $zone): array => ['value' => (string) $zone->id, 'label' => $zone->name]),
        ]]);
    }

    public function store(GoalRequest $request): JsonResponse
    {
        $this->authorizeAny('goals.manage');
        $data = $request->goalData();
        $this->assertTargetAccessible($data);

        $goal = Goal::create($data + ['company_id' => $this->currentEmployee()->company_id, 'created_by' => $this->currentEmployee()->id]);

        return $this->message('Goal Registered successfully', 201, ['data' => new GoalResource($goal->load(['branch', 'zone', 'employee', 'creator']))]);
    }

    /**
     * Goal detail with the cumulative progress series for the chart.
     */
    public function show(Goal $goal, GoalProgress $progress): JsonResponse
    {
        $this->authorizeAny('goals.view', 'goals.manage');
        abort_unless($this->visibleGoals()->whereKey($goal->id)->exists(), 404);

        return response()->json(['data' => [
            'goal' => new GoalResource($goal->load(['branch', 'zone', 'employee', 'creator'])),
            'series' => $progress->series($goal),
        ]]);
    }

    public function update(GoalRequest $request, Goal $goal): JsonResponse
    {
        $this->authorizeAny('goals.manage');
        abort_unless($this->visibleGoals()->whereKey($goal->id)->exists(), 404);
        $data = $request->goalData();
        $this->assertTargetAccessible($data);

        $goal->update($data);

        return $this->message('Goal Updated successfully', 200, ['data' => new GoalResource($goal->load(['branch', 'zone', 'employee', 'creator']))]);
    }

    public function destroy(Goal $goal): JsonResponse
    {
        $this->authorizeAny('goals.manage');
        abort_unless($this->visibleGoals()->whereKey($goal->id)->exists(), 404);

        $goal->delete();

        return $this->message('Goal Deleted successfully');
    }

    /**
     * Performance report for graphs: actual results per branch and per officer for a metric and date range
     * (defaults to the current month), with goal status counts for goals overlapping that range.
     */
    public function report(Request $request): JsonResponse
    {
        $this->authorizeAny('goals.view', 'goals.manage');

        $metric = in_array($request->input('metric'), array_keys(Goal::METRICS), true) ? $request->string('metric')->toString() : 'disbursement_amount';
        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString()) : CarbonImmutable::today()->startOfMonth();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString()) : CarbonImmutable::today()->endOfMonth();
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to' => 'The to date must be after the from date.']);
        }

        $branches = $this->visibleBranches()->keyBy('id');
        $branchTargets = [];
        $goals = $this->visibleGoals()->with(['branch', 'zone', 'employee'])
            ->where('metric', $metric)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get();
        foreach ($goals->where('scope_type', 'branch') as $goal) {
            $branchTargets[$goal->branch_id] = ($branchTargets[$goal->branch_id] ?? 0) + (float) $goal->target;
        }

        [$byBranch, $byEmployee] = $this->actuals($metric, $from, $to, $branches->keys()->all());

        $progress = app(GoalProgress::class);
        $summaries = $goals->map(fn (Goal $goal): array => ['goal' => $goal, 'progress' => $progress->summary($goal)]);

        $employees = Employee::whereIn('id', array_keys($byEmployee))->with('branch')->get()->keyBy('id');

        return response()->json(['data' => [
            'metric' => $metric,
            'metric_label' => Goal::METRICS[$metric],
            'is_money' => in_array($metric, Goal::MONEY_METRICS, true),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'branches' => $branches->map(fn (Branch $branch): array => [
                'branch_id' => $branch->id,
                'branch' => $branch->name,
                'actual' => round($byBranch[$branch->id] ?? 0, 2),
                'target' => round($branchTargets[$branch->id] ?? 0, 2),
            ])->sortByDesc('actual')->values(),
            'officers' => collect($byEmployee)->map(fn (float $value, int $id): array => [
                'employee_id' => $id,
                'employee' => $employees->get($id)?->full_name ?? '-',
                'branch' => $employees->get($id)?->branch?->name,
                'actual' => round($value, 2),
            ])->sortByDesc('actual')->take(20)->values(),
            'goals' => $summaries->map(fn (array $row): array => [
                'id' => $row['goal']->id,
                'title' => $row['goal']->title,
                'assignee' => match ($row['goal']->scope_type) {
                    'branch' => $row['goal']->branch?->name,
                    'zone' => $row['goal']->zone?->name,
                    'employee' => $row['goal']->employee?->full_name,
                    default => 'ALL BRANCHES',
                },
            ] + $row['progress'])->values(),
            'status_counts' => $summaries->countBy(fn (array $row): string => $row['progress']['status']),
        ]]);
    }

    /**
     * Goals the signed-in employee may see. Company-scope managers see all; others see company-wide goals,
     * goals of branches / zones in their scope, and officer goals of staff in their scope
     * (inferred: branch staff other than managers only see their own officer goals).
     *
     * @return Builder<Goal>
     */
    private function visibleGoals(): Builder
    {
        $me = $this->currentEmployee();
        $branchIds = app(AccessControl::class)->branchIds($me);
        $query = Goal::query()->where('company_id', $me->company_id);

        if ($branchIds === null) {
            return $query;
        }

        $zoneIds = Branch::whereIn('id', $branchIds)->whereNotNull('zone_id')->pluck('zone_id')->unique()->all();
        $isHead = $me->role?->scope === 'zone' || $me->role?->key === 'branch_manager' || $me->can('goals.manage');

        return $query->where(fn (Builder $inner) => $inner
            ->where('scope_type', 'company')
            ->orWhere(fn (Builder $branch) => $branch->where('scope_type', 'branch')->whereIn('branch_id', $branchIds))
            ->orWhere(fn (Builder $zone) => $zone->where('scope_type', 'zone')->whereIn('zone_id', $zoneIds))
            ->orWhere(fn (Builder $officer) => $officer->where('scope_type', 'employee')->when(
                $isHead,
                fn (Builder $scoped) => $scoped->whereIn('employee_id', Employee::staff()->whereIn('branch_id', $branchIds)->select('id')),
                fn (Builder $own) => $own->where('employee_id', $me->id),
            )));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertTargetAccessible(array $data): void
    {
        $branchIds = app(AccessControl::class)->branchIds($this->currentEmployee());

        match ($data['scope_type']) {
            'branch' => $this->assertBranchAccessible((int) $data['branch_id']),
            'employee' => abort_unless($this->scoped(Employee::query())->staff()->whereKey($data['employee_id'])->exists(), 403, 'You do not have access to this employee.'),
            'zone' => abort_unless($branchIds === null || Branch::where('zone_id', $data['zone_id'])->whereIn('id', $branchIds)->exists(), 403, 'You do not have access to this zone.'),
            default => abort_unless($branchIds === null, 403, 'Only head office can set company-wide goals.'),
        };
    }

    /**
     * Actual results per branch and per officer.
     *
     * @param  list<int>  $branchIds
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    private function actuals(string $metric, CarbonImmutable $from, CarbonImmutable $to, array $branchIds): array
    {
        $companyId = $this->currentEmployee()->company_id;

        if ($metric === 'new_customers') {
            $base = fn () => Customer::where('company_id', $companyId)->whereIn('branch_id', $branchIds)
                ->whereDate('created_at', '>=', $from->toDateString())->whereDate('created_at', '<=', $to->toDateString());

            return [
                $base()->selectRaw('branch_id as k, COUNT(*) as v')->groupBy('branch_id')->toBase()->pluck('v', 'k')->map(fn ($v): float => (float) $v)->all(),
                $base()->whereNotNull('employee_id')->selectRaw('employee_id as k, COUNT(*) as v')->groupBy('employee_id')->toBase()->pluck('v', 'k')->map(fn ($v): float => (float) $v)->all(),
            ];
        }

        $type = $metric === 'collections_amount' ? 'deposit' : 'withdrawal';
        $aggregate = $metric === 'loans_count' ? 'COUNT(*)' : 'SUM(loan_transactions.amount)';
        $base = fn () => LoanTransaction::where('loan_transactions.company_id', $companyId)
            ->whereIn('loan_transactions.branch_id', $branchIds)
            ->where('loan_transactions.type', $type)
            ->whereNull('loan_transactions.reversed_at')
            ->whereBetween('loan_transactions.transaction_date', [$from->toDateString(), $to->toDateString()]);

        return [
            $base()->selectRaw("loan_transactions.branch_id as k, {$aggregate} as v")->groupBy('loan_transactions.branch_id')->toBase()->pluck('v', 'k')->map(fn ($v): float => (float) $v)->all(),
            $base()->join('loans', 'loans.id', '=', 'loan_transactions.loan_id')->whereNotNull('loans.employee_id')
                ->selectRaw("loans.employee_id as k, {$aggregate} as v")->groupBy('loans.employee_id')->toBase()->pluck('v', 'k')->map(fn ($v): float => (float) $v)->all(),
        ];
    }
}
