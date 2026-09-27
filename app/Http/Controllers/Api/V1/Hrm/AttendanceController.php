<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Http\Requests\Api\Hrm\AttendanceRequest;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\Leave;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HRM → Attendance (handwritten HR note: "Attendance connected to HR's system"): daily check-in /
 * check-out per employee and a monthly summary.
 *
 * Inferred: a check-in after the company's work start time is "late"; a day without a record is
 * "absent" (or "leave" when an approved leave covers it).
 */
class AttendanceController extends HrmController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage');

        $date = $request->filled('date') ? CarbonImmutable::parse($request->string('date')->toString()) : CarbonImmutable::today();
        $employees = $this->applyFilters($this->scoped(Employee::query())->staff()->where('status', 'active'), $request)->with('branch')->orderBy('first_name')->get();
        $records = Attendance::whereIn('employee_id', $employees->pluck('id'))->whereDate('date', $date->toDateString())->get()->keyBy('employee_id');
        $onLeave = $this->onLeave($employees->pluck('id')->all(), $date, $date);

        return response()->json(['data' => [
            'date' => $date->toDateString(),
            'work_start_time' => HrmSetting::forCompany($this->companyId())->work_start_time,
            'rows' => $employees->map(function (Employee $employee) use ($records, $onLeave, $date): array {
                $record = $records->get($employee->id);

                return [
                    'employee_id' => $employee->id,
                    'employee' => $employee->full_name,
                    'employee_number' => $employee->employee_number,
                    'branch' => $employee->branch?->name,
                    'attendance_id' => $record?->id,
                    'check_in' => $record?->check_in ? substr((string) $record->check_in, 0, 5) : null,
                    'check_out' => $record?->check_out ? substr((string) $record->check_out, 0, 5) : null,
                    'hours' => $record?->hoursWorked() ?? 0,
                    'status' => $record?->status ?? (in_array($employee->id, $onLeave[$date->toDateString()] ?? [], true) ? 'leave' : 'absent'),
                    'remarks' => $record?->remarks,
                ];
            })->values(),
        ]]);
    }

    /**
     * Check in: HR for an employee (empl_id) or any signed-in employee for themselves.
     */
    public function checkIn(Request $request): JsonResponse
    {
        $employee = $this->target($request);
        $now = now();

        $record = Attendance::firstOrNew(['employee_id' => $employee->id, 'date' => $now->toDateString()]);
        if ($record->exists && $record->check_in !== null) {
            return $this->message('Already checked in today', 422);
        }

        $start = HrmSetting::forCompany($this->companyId())->work_start_time;
        $record->fill([
            'company_id' => $employee->company_id,
            'branch_id' => $employee->branch_id,
            'check_in' => $now->format('H:i:s'),
            'status' => $now->format('H:i') > $start ? 'late' : 'present',
            'recorded_by' => $this->currentEmployee()->id,
        ])->save();

        return $this->message('Checked in successfully', 200, ['data' => ['check_in' => $now->format('H:i'), 'status' => $record->status]]);
    }

    public function checkOut(Request $request): JsonResponse
    {
        $employee = $this->target($request);

        $record = Attendance::where('employee_id', $employee->id)->whereDate('date', now()->toDateString())->first();
        if ($record === null || $record->check_in === null) {
            return $this->message('You have not checked in today', 422);
        }
        if ($record->check_out !== null) {
            return $this->message('Already checked out today', 422);
        }

        $record->update(['check_out' => now()->format('H:i:s')]);

        return $this->message('Checked out successfully');
    }

    /**
     * Manual record / correction by HR.
     */
    public function store(AttendanceRequest $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage');

        $employee = Employee::staff()->findOrFail($request->integer('empl_id'));
        $this->ensureVisible($employee);

        Attendance::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $request->date('date')->toDateString()],
            [
                'company_id' => $employee->company_id,
                'branch_id' => $employee->branch_id,
                'check_in' => $request->input('check_in'),
                'check_out' => $request->input('check_out'),
                'status' => $request->string('status')->toString(),
                'remarks' => $request->input('remarks'),
                'recorded_by' => $this->currentEmployee()->id,
            ],
        );

        return $this->message('Attendance Saved successfully');
    }

    /**
     * Monthly summary per employee: present, late, absent, leave days and hours worked (working days = Mon–Sat, inferred).
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage');

        $month = $this->month($request, 'month');
        $end = $month->endOfMonth()->min(CarbonImmutable::today());
        $employees = $this->applyFilters($this->scoped(Employee::query())->staff()->where('status', 'active'), $request)->with('branch')->orderBy('first_name')->get();
        $records = Attendance::whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('date', '>=', $month->toDateString())
            ->whereDate('date', '<=', $month->endOfMonth()->toDateString())
            ->get()
            ->groupBy('employee_id');
        $onLeave = $this->onLeave($employees->pluck('id')->all(), $month, $end);

        $workingDays = [];
        for ($day = $month; $day <= $end; $day = $day->addDay()) {
            if (! $day->isSunday()) {
                $workingDays[] = $day->toDateString();
            }
        }

        return response()->json(['data' => [
            'month' => $month->format('Y-m'),
            'working_days' => count($workingDays),
            'rows' => $employees->map(function (Employee $employee) use ($records, $workingDays, $onLeave): array {
                $days = ($records->get($employee->id) ?? collect())->keyBy(fn (Attendance $record): string => $record->date->toDateString());
                $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0];
                foreach ($workingDays as $day) {
                    $status = $days->get($day)?->status ?? (in_array($employee->id, $onLeave[$day] ?? [], true) ? 'leave' : 'absent');
                    $counts[$status]++;
                }

                return [
                    'employee_id' => $employee->id,
                    'employee' => $employee->full_name,
                    'branch' => $employee->branch?->name,
                    ...$counts,
                    'hours' => round((float) $days->sum(fn (Attendance $record): float => $record->hoursWorked()), 2),
                    'attendance_rate' => count($workingDays) > 0 ? round(($counts['present'] + $counts['late']) / count($workingDays) * 100, 1) : 0,
                ];
            })->values(),
        ]]);
    }

    private function target(Request $request): Employee
    {
        if ($request->filled('empl_id')) {
            $this->authorizeAny('hrm.manage');
            $request->validate(['empl_id' => [Rule::exists('employees', 'id')->where('company_id', $this->companyId())]]);
            $employee = Employee::staff()->findOrFail($request->integer('empl_id'));
            $this->ensureVisible($employee);

            return $employee;
        }

        $this->authorizeAny('dashboard.view', 'hrm.manage');

        return $this->currentEmployee();
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<string, list<int>> date => employee ids on approved leave
     */
    private function onLeave(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $map = [];
        $leaves = Leave::whereIn('employee_id', $employeeIds)->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->toDateString())->whereDate('end_date', '>=', $from->toDateString())->get();

        foreach ($leaves as $leave) {
            for ($day = CarbonImmutable::parse($leave->start_date)->max($from); $day <= CarbonImmutable::parse($leave->end_date)->min($to); $day = $day->addDay()) {
                $map[$day->toDateString()][] = $leave->employee_id;
            }
        }

        return $map;
    }
}
