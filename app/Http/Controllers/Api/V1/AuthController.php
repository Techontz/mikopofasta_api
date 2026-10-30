<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LoanStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EmployeeResource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Loan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * The three figures under the login page picture (public, counts only): running loans (active, overdue or default),
     * the share of them not in arrears, and active branches.
     */
    public function loginStats(): JsonResponse
    {
        $running = Loan::query()->status(LoanStatus::Active, LoanStatus::Overdue, LoanStatus::Default)->count();
        $onTime = Loan::query()->status(LoanStatus::Active)->count();

        return response()->json(['data' => [
            'active_loans' => $running,
            'on_time_repayment' => $running === 0 ? null : round($onTime / $running * 100, 1),
            'branches' => Branch::query()->where('status', 'active')->count(),
        ]]);
    }

    /**
     * Phone + password login (live: "Login to your account"). Issues a Sanctum token.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string'],
            'device' => ['nullable', 'string', 'max:100'],
        ]);

        $employee = Employee::with(['company', 'branch', 'role.permissions', 'zone', 'shareHolder'])->where('phone', $credentials['phone'])->first();

        if ($employee === null || ! Hash::check($credentials['password'], $employee->password)) {
            throw ValidationException::withMessages(['phone' => 'Phone number or password is incorrect']);
        }

        if ($employee->status !== 'active') {
            throw ValidationException::withMessages(['phone' => 'Your account is not active. Contact the administrator.']);
        }

        $token = $employee->createToken($credentials['device'] ?? 'web')->plainTextToken;

        AuditLog::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'action' => 'Auth.login',
            'auditable_type' => $employee->getMorphClass(),
            'auditable_id' => $employee->id,
            'context' => ['account_type' => $employee->account_type ?? Employee::ACCOUNT_STAFF, 'device' => $credentials['device'] ?? 'web', 'must_change_password' => (bool) $employee->must_change_password],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'token' => $token,
            'user' => new EmployeeResource($employee),
        ]);
    }

    public function me(Request $request): EmployeeResource
    {
        return new EmployeeResource($request->user()->load(['company', 'branch', 'role.permissions', 'zone', 'shareHolder']));
    }

    /**
     * Change the signed-in account's own password (the first-login step for a temporary password): current password,
     * a strong confirmed new password that differs from it. Clears must_change_password, signs out every other session
     * and is audit-logged (without any password).
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(8)->letters()->mixedCase()->numbers()],
        ], [], ['current_password' => 'current password', 'password' => 'new password']);

        /** @var Employee $employee */
        $employee = $request->user();
        if (! Hash::check($validated['current_password'], $employee->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        DB::transaction(function () use ($employee, $validated, $request): void {
            $wasRequired = (bool) $employee->must_change_password;
            $employee->forceFill(['password' => $validated['password'], 'must_change_password' => false])->save();

            $current = $employee->currentAccessToken();
            $employee->tokens()->when($current !== null && isset($current->id), fn ($query) => $query->whereKeyNot($current->id))->delete();

            AuditLog::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'action' => 'Employee.password_changed',
                'auditable_type' => $employee->getMorphClass(),
                'auditable_id' => $employee->id,
                'before' => ['must_change_password' => $wasRequired],
                'after' => ['must_change_password' => false, 'other_sessions_revoked' => true],
                'context' => ['account_type' => $employee->account_type],
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json(['message' => 'Password changed successfully', 'data' => new EmployeeResource($employee->fresh(['company', 'branch', 'role.permissions', 'zone', 'shareHolder']))]);
    }

    /**
     * GET auth/roles — the roles the signed-in employee may switch to, and the one this session acts as.
     */
    public function roles(Request $request): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();

        return response()->json(['data' => [
            'active_role_id' => $employee->role_id,
            'roles' => $employee->assignedRoles()->map(fn ($role): array => ['id' => $role->id, 'key' => $role->key, 'name' => $role->name, 'primary' => $role->id === $employee->primaryRoleId()])->values(),
        ]]);
    }

    /**
     * POST auth/switch-role — act as another of the employee's assigned roles without signing out (enhancement §13). Only
     * a role the employee is assigned can be chosen; the choice is kept on this session's token, revalidated on every
     * request, and audit-logged. Nothing else changes.
     */
    public function switchRole(Request $request): EmployeeResource
    {
        $validated = $request->validate(['role_id' => ['required', 'integer']]);
        /** @var Employee $employee */
        $employee = $request->user();
        $token = $employee->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 422, 'Switching role needs a signed-in session.');
        abort_if($employee->isShareholderAccount(), 403, 'Shareholder portal accounts have a single role.');

        $role = $employee->assignedRoles()->firstWhere('id', (int) $validated['role_id']);
        abort_if($role === null || $role->key === 'shareholder', 403, 'You are not assigned this role.');

        $from = $employee->role_id;
        $token->forceFill(['active_role_id' => $role->id === $employee->primaryRoleId() ? null : $role->id])->save();
        if ($role->id !== $employee->primaryRoleId()) {
            $employee->actAsRole($role);
        } elseif ($employee->switchedFromRoleId !== null) {
            $employee->forceFill(['role_id' => $employee->primaryRoleId()])->syncOriginalAttribute('role_id');
            $employee->switchedFromRoleId = null;
        }

        AuditLog::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'action' => 'Employee.role_switched',
            'auditable_type' => $employee->getMorphClass(),
            'auditable_id' => $employee->id,
            'before' => ['role_id' => $from],
            'after' => ['role_id' => $role->id],
            'ip_address' => $request->ip(),
        ]);

        return new EmployeeResource($employee->load(['company', 'branch', 'role.permissions', 'zone', 'shareHolder']));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out']);
    }
}
