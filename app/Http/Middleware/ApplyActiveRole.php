<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Switch Role (enhancement §13): a session that switched to another of the employee's roles acts as that role for every
 * permission, menu, report and branch-scope check. The choice lives on the token and is revalidated on each request, so a
 * role revoked meanwhile stops applying at once and the session falls back to the primary role.
 */
class ApplyActiveRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $employee = $request->user();
        $token = $employee instanceof Employee ? $employee->currentAccessToken() : null;

        if ($token instanceof PersonalAccessToken && $token->exists === true && is_numeric($token->active_role_id)) {
            $role = $employee->assignedRoles()->firstWhere('id', (int) $token->active_role_id);

            if ($role instanceof Role && $role->key !== 'shareholder' && ! $employee->isShareholderAccount()) {
                if ($role->id !== $employee->primaryRoleId()) {
                    $employee->actAsRole($role);
                }
            } else {
                $token->forceFill(['active_role_id' => null])->save();
            }
        }

        return $next($request);
    }
}
