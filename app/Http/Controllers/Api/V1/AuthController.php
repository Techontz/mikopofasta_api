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

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out']);
    }
}
