<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Enums\Account;
use App\Enums\StaffCreditStatus;
use App\Http\Requests\Api\Hrm\StaffRequest;
use App\Http\Requests\Api\Hrm\StaffSalaryRequest;
use App\Http\Resources\Api\V1\Hrm\SalaryChangeRequestResource;
use App\Http\Resources\Api\V1\Hrm\SalaryPaymentResource;
use App\Http\Resources\Api\V1\Hrm\StaffLoanResource;
use App\Http\Resources\Api\V1\Hrm\StaffResource;
use App\Http\Resources\Api\V1\Hrm\StaffSalaryAdvanceResource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\NegligenceDeduction;
use App\Models\SalaryChangeRequest;
use App\Models\StaffAllowance;
use App\Services\Hrm\EmployeeNumberGenerator;
use App\Services\Hrm\SalaryChanges;
use App\Services\Hrm\StaffFund;
use App\Services\Hrm\StaffPasswordReset;
use App\Services\Ledger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * HRM → All active staff, All Rejected staff, Branch & Staff and the employee profile
 * (live admin/all_employee, all_rejected_employee, view_blanchEmployee, view_employee/:id).
 */
class StaffController extends HrmController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('hrm.manage', 'users.manage');

        $rejected = $request->string('status')->toString() === 'rejected';

        $employees = $this->scoped(Employee::query())->staff()
            ->when($rejected, fn ($query) => $query->where('status', 'rejected'), fn ($query) => $query->where('status', '!=', 'rejected'))
            ->with(['branch', 'role', 'zone'])
            ->orderByDesc('id')
            ->get();

        return StaffResource::collection($employees);
    }

    /**
     * Live default password: the phone number (unless a password is given). Empl/ID follows the live
     * pattern MK-<3 digit sequence><year>, reserved by EmployeeNumberGenerator inside the create transaction (retried
     * once when a concurrent registration took the same number). Documents: the staff ledger accounts (control, loan,
     * advance, deductions/fund) are opened on registration.
     */
    public function store(StaffRequest $request, Ledger $ledger, EmployeeNumberGenerator $numbers): JsonResponse
    {
        $this->authorizeAny('users.manage', 'hrm.manage');

        $data = $request->employeeData();
        if ($data['branch_id'] !== null) {
            $this->assertBranchAccessible($data['branch_id']);
        }

        $companyId = $this->companyId();
        $register = fn (): Employee => DB::transaction(function () use ($request, $data, $companyId, $ledger, $numbers): Employee {
            $employee = Employee::create($data + [
                'company_id' => $companyId,
                'employee_number' => $numbers->next($companyId),
                'status' => 'active',
                'password' => $request->filled('password') ? $request->string('password')->toString() : $data['phone'],
            ]);

            if ($salary = $request->salaryData()) {
                $employee->salaryInfo()->create($salary);
            }

            foreach ([Account::StaffPayable, Account::StaffLoanReceivable, Account::StaffAdvanceReceivable, Account::StaffFund] as $account) {
                $ledger->account($companyId, $account, employee: $employee);
            }

            $this->audit('Employee.registered', $employee, null, ['role_id' => $employee->role_id, 'branch_id' => $employee->branch_id]);

            return $employee;
        });

        try {
            $employee = $register();
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'employee_number')) {
                throw $exception;
            }
            $employee = $register();
        }

        return $this->message('Employee Registered successfully', 201, ['data' => new StaffResource($employee->load(['branch', 'role', 'zone', 'salaryInfo']))]);
    }

    public function show(Employee $employee): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'users.manage');
        $this->ensureVisible($employee);

        $employee->load([
            'branch', 'role', 'zone', 'salaryInfo',
            'allowances' => fn ($query) => $query->latest('id'),
            'deductions' => fn ($query) => $query->latest('id'),
            'salaryAdvances' => fn ($query) => $query->latest('id'),
            'staffLoans' => fn ($query) => $query->whereNotIn('status', [StaffCreditStatus::Submitted->value])->withSum('payments', 'amount')->latest('id'),
            'salaryPayments' => fn ($query) => $query->latest('id'),
        ]);

        $amountRow = fn ($item): array => [
            'id' => $item->id,
            'amount' => (float) $item->amount,
            'description' => $item->description,
            'status' => $item->status,
            'created_at' => $item->created_at?->toDateTimeString(),
        ];

        return response()->json(['data' => (new StaffResource($employee))->resolve() + [
            'allowances' => $employee->allowances->map(fn (StaffAllowance $allowance): array => $amountRow($allowance) + [
                'reason' => $allowance->reason,
                'payroll_period' => $allowance->payroll_period?->format('Y-m'),
                'status_label' => $allowance->statusLabel(),
            ]),
            'deductions' => $employee->deductions->map($amountRow),
            'salary_advances' => StaffSalaryAdvanceResource::collection($employee->salaryAdvances)->resolve(),
            'staff_loans' => StaffLoanResource::collection($employee->staffLoans)->resolve(),
            'salary_changes' => SalaryChangeRequestResource::collection(SalaryChangeRequest::where('employee_id', $employee->id)->with(['employee', 'requester', 'approver', 'rejecter'])->latest('id')->limit(20)->get())->resolve(),
            'salary_payments' => SalaryPaymentResource::collection($employee->salaryPayments)->resolve(),
            'staff_fund_balance' => app(Ledger::class)->balance($employee->company_id, Account::StaffFund, employee: $employee->id),
            'staff_fund_benefit' => app(StaffFund::class)->benefitRecord((int) $employee->company_id, $employee->id),
            'negligence_deductions' => NegligenceDeduction::where('employee_id', $employee->id)->latest('id')->get()->map(fn (NegligenceDeduction $deduction): array => [
                'id' => $deduction->id,
                'amount' => (float) $deduction->amount,
                'recovered_amount' => (float) $deduction->recovered_amount,
                'outstanding_amount' => $deduction->outstandingAmount(),
                'reason' => $deduction->reason,
                'status' => $deduction->status,
                'created_at' => $deduction->created_at?->toDateTimeString(),
            ]),
        ]]);
    }

    public function update(StaffRequest $request, Employee $employee): JsonResponse
    {
        $this->authorizeAny('users.manage', 'hrm.manage');
        $this->ensureVisible($employee);

        $data = $request->employeeData();
        if ($data['branch_id'] !== null && $data['branch_id'] !== $employee->branch_id) {
            $this->assertBranchAccessible($data['branch_id']);
        }

        $before = $employee->only(['role_id', 'zone_id', 'branch_id', 'position']);
        $employee->update($data);
        if ($before !== $employee->only(['role_id', 'zone_id', 'branch_id', 'position'])) {
            $this->audit('Employee.assignment_changed', $employee, $before, $employee->only(['role_id', 'zone_id', 'branch_id', 'position']));
        }

        return $this->message('Employee Updated successfully', 200, ['data' => new StaffResource($employee->load(['branch', 'role', 'zone']))]);
    }

    /**
     * Cropped passport photo as a base64 data URL, or a plain file upload.
     */
    public function photo(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeAny('users.manage', 'hrm.manage');
        $this->ensureVisible($employee);

        if ($request->hasFile('image')) {
            $request->validate(['image' => ['required', 'image', 'max:5120']]);
            $path = $request->file('image')->store('employees/photos', 'public');
        } else {
            $request->validate(['image' => ['required', 'string', 'starts_with:data:image/']]);
            [, $encoded] = explode(',', $request->string('image')->toString(), 2) + [1 => ''];
            $binary = base64_decode($encoded, true);
            abort_if($binary === false || @getimagesizefromstring($binary) === false, 422, 'Invalid image');

            $path = 'employees/photos/'.$employee->id.'-'.Str::random(8).'.png';
            Storage::disk('public')->put($path, $binary);
        }

        if ($employee->photo) {
            Storage::disk('public')->delete($employee->photo);
        }
        $employee->update(['photo' => $path]);

        return $this->message('Passport uploaded successfully', 200, ['url' => $employee->photo_url]);
    }

    /**
     * Salary structure (STAFF COMMISSION §3). Salary can not be changed while the employee is on an approved payroll that has
     * not been paid (§16 "Salary haiwezi kubadilishwa baada ya approval"). Spec §32: a change of an existing salary's pay terms
     * (and any change of the proposer's own salary) becomes a request approved by Finance / Admin ({@see SalaryChanges}); the
     * first salary of a new employee and banking details are saved directly.
     */
    public function salary(StaffSalaryRequest $request, Employee $employee, SalaryChanges $changes): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'payroll.approve');
        $this->ensureVisible($employee);

        $values = $request->salaryData();
        if ($changes->isLocked($employee, $values)) {
            return $this->message(SalaryChanges::LOCKED_MESSAGE, 422, ['errors' => ['salary' => [SalaryChanges::LOCKED_MESSAGE]]]);
        }

        if ($changes->requiresApproval($employee, $values, $this->currentEmployee())) {
            $change = $changes->propose($employee, $values, $this->currentEmployee(), $request->string('reason')->trim()->toString() ?: null);

            return $this->message('Salary change submitted for approval', 202, ['data' => ['salary_change_id' => $change->id, 'approval_stage' => $change->approval_stage]]);
        }

        $before = $employee->salaryInfo?->only(['salary', 'salary_type', 'commission_eligible', 'payment_method', 'account_number']);
        $salary = $employee->salaryInfo()->updateOrCreate(['employee_id' => $employee->id], $values);
        $this->audit('EmployeeSalary.saved', $employee, $before, $salary->only(['salary', 'salary_type', 'commission_eligible', 'payment_method', 'account_number']));

        return $this->message('Salary Information Saved successfully');
    }

    public function password(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeAny('users.manage', 'hrm.manage');
        $this->ensureVisible($employee);

        $request->validate([
            'oldpass' => ['required', 'string'],
            'newpass' => ['required', 'string', 'min:4'],
            'passconf' => ['required', 'same:newpass'],
        ], ['passconf.same' => 'Password does not match']);

        if (! Hash::check($request->string('oldpass')->toString(), $employee->password)) {
            return $this->message('Old password is incorrect', 422, ['errors' => ['oldpass' => ['Old password is incorrect']]]);
        }

        $employee->update(['password' => $request->string('newpass')->toString()]);
        $this->audit('Employee.password_changed', $employee, null, null);

        return $this->message('Password changed successfully');
    }

    public function toggleBlock(Employee $employee): JsonResponse
    {
        $this->authorizeAny('users.manage');
        $this->ensureVisible($employee);

        if ($employee->is($this->currentEmployee())) {
            return $this->message('You can not block your own account', 422);
        }

        $blocked = $employee->status !== 'blocked';
        $employee->update(['status' => $blocked ? 'blocked' : 'active']);
        if ($blocked) {
            $employee->tokens()->delete();
        }
        $this->audit($blocked ? 'Employee.blocked' : 'Employee.unblocked', $employee, null, null);

        return $this->message($blocked ? 'Employee Blocked successfully' : 'Employee Unblocked successfully');
    }

    /**
     * Live header buttons "Block Account" / "Un Block Account" (all staff except the signed-in user).
     */
    public function blockAll(Request $request): JsonResponse
    {
        $this->authorizeAny('users.manage');

        $block = $request->boolean('block', true);
        $query = $this->scoped(Employee::query())->staff()
            ->whereKeyNot($this->currentEmployee()->id)
            ->where('status', $block ? 'active' : 'blocked');

        $ids = $query->pluck('id');
        Employee::whereIn('id', $ids)->update(['status' => $block ? 'blocked' : 'active']);
        if ($block) {
            DB::table('personal_access_tokens')->where('tokenable_type', (new Employee)->getMorphClass())->whereIn('tokenable_id', $ids)->delete();
        }
        AuditLog::create([
            'company_id' => $this->companyId(),
            'employee_id' => $this->currentEmployee()->id,
            'action' => $block ? 'Employee.block_all' : 'Employee.unblock_all',
            'after' => ['employee_ids' => $ids->all()],
            'ip_address' => request()->ip(),
        ]);

        return $this->message($block ? 'All Employee Blocked successfully' : 'All Employee Unblocked successfully');
    }

    public function reject(Employee $employee): JsonResponse
    {
        $this->authorizeAny('users.manage');
        $this->ensureVisible($employee);

        if ($employee->is($this->currentEmployee())) {
            return $this->message('You can not reject your own account', 422);
        }

        $employee->update(['status' => 'rejected']);
        $employee->tokens()->delete();
        $this->audit('Employee.rejected', $employee, null, null);

        return $this->message('Employee Rejected successfully');
    }

    /**
     * Live "reset_panel": resets the staff password to the single configured default password
     * (config hrm.default_staff_password / env DEFAULT_STAFF_PASSWORD) and revokes the staff member's tokens.
     * Nobody resets their own password here (use Change password), and only a Super Admin resets a Super Admin.
     */
    public function resetPassword(Employee $employee, StaffPasswordReset $passwordReset): JsonResponse
    {
        $this->authorizeAny('hrm.staff_reset_password');
        $this->ensureVisible($employee);

        $actor = $this->currentEmployee();
        abort_if($employee->is($actor), 403, 'You can not reset your own password here. Use Change password instead.');
        abort_if($employee->role?->key === 'super_admin' && $actor->role?->key !== 'super_admin', 403, 'Only a Super Admin can reset a Super Admin password.');

        if (! $passwordReset->isConfigured()) {
            return $this->message('The default staff password is not configured.', 503);
        }

        $passwordReset->reset($employee, $actor);

        return $this->message('Password reset successfully to the configured default password.');
    }

    /**
     * Staff with money history (payroll, loans, advances, ledger) can not be deleted — reject them instead.
     */
    public function destroy(Employee $employee): JsonResponse
    {
        $this->authorizeAny('users.manage');
        $this->ensureVisible($employee);

        if ($employee->is($this->currentEmployee())) {
            return $this->message('You can not delete your own account', 422);
        }

        $hasHistory = $employee->salaryPayments()->exists() || $employee->staffLoans()->exists() || $employee->salaryAdvances()->exists()
            || DB::table('payroll_items')->where('employee_id', $employee->id)->exists()
            || DB::table('journal_entries')->where('employee_id', $employee->id)->exists();

        if ($hasHistory) {
            return $this->message('Employee has transactions and cannot be deleted', 422);
        }

        if ($employee->photo) {
            Storage::disk('public')->delete($employee->photo);
        }
        $this->audit('Employee.deleted', $employee, $employee->only(['first_name', 'last_name', 'phone', 'branch_id', 'role_id']), null);
        $employee->delete();

        return $this->message('Employee Deleted successfully');
    }

    /**
     * HRM → Branch & Staff (live view_blanchEmployee).
     */
    public function branches(): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'users.manage');

        $branches = Branch::whereIn('id', $this->visibleBranches()->pluck('id'))
            ->with(['region', 'employees' => fn ($query) => $query->with('role')->orderBy('id')])
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $branches->map(fn (Branch $branch): array => [
            'id' => $branch->id,
            'name' => $branch->name,
            'phone' => $branch->phone,
            'region' => $branch->region?->name,
            'employees' => StaffResource::collection($branch->employees)->resolve(),
        ])]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(string $action, Employee $employee, ?array $before, ?array $after): void
    {
        AuditLog::create([
            'company_id' => $employee->company_id,
            'employee_id' => $this->currentEmployee()->id,
            'action' => $action,
            'auditable_type' => $employee->getMorphClass(),
            'auditable_id' => $employee->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => request()->ip(),
        ]);
    }
}
