<?php

namespace App\Http\Middleware;

use App\Models\Collateral;
use App\Models\EmployeePrivilege;
use App\Models\Guarantor;
use App\Models\LegacyImportRow;
use App\Models\LoanSchedule;
use App\Models\PenaltyPayment;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prevents one company's users from reaching another company's records via route model binding.
 */
class EnsureCompanyOwnership
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum') ?? $request->user();

        if ($user !== null && $request->route() !== null) {
            foreach ($request->route()->parameters() as $parameter) {
                if ($parameter instanceof Model && ! $this->belongsToCompany($parameter, $user->company_id)) {
                    abort(404);
                }
            }
        }

        return $next($request);
    }

    private function belongsToCompany(Model $model, int $companyId): bool
    {
        $owner = match (true) {
            $model instanceof Guarantor => $model->customer,
            $model instanceof Collateral, $model instanceof LoanSchedule => $model->loan,
            $model instanceof PenaltyPayment => $model->penalty,
            $model instanceof EmployeePrivilege => $model->employee,
            $model instanceof LegacyImportRow => $model->import,
            default => $model,
        };

        if (! array_key_exists('company_id', $owner?->getAttributes() ?? [])) {
            return true;
        }

        return (int) $owner->company_id === $companyId;
    }
}
