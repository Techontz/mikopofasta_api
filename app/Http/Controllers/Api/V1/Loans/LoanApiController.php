<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Loan;
use App\Services\AccessControl;

/**
 * Shared helpers for the Loans API: branch-scope checks on route-bound loans.
 */
abstract class LoanApiController extends ApiController
{
    /**
     * Loans outside the employee's company / zone / branch scope are treated as not found.
     */
    protected function ensureVisible(Loan $loan): void
    {
        $employee = $this->currentEmployee();
        $branchIds = app(AccessControl::class)->branchIds($employee);

        abort_unless((int) $loan->company_id === (int) $employee->company_id && ($branchIds === null || in_array((int) $loan->branch_id, $branchIds, true)), 404);
    }
}
