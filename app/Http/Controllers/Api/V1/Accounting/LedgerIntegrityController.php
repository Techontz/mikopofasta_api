<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Services\Accounting\LedgerIntegrity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Accounting → ledger integrity report for the signed-in employee's company (same checks as `ledger:reconcile`).
 */
class LedgerIntegrityController extends ApiController
{
    public function __invoke(LedgerIntegrity $integrity): JsonResponse
    {
        $this->authorizeAny('accounting.view');

        $report = $integrity->run((int) $this->currentEmployee()->company_id);

        if (! Gate::allows('capital.view')) {
            $report['checks'] = array_map(
                fn (array $check): array => $check['key'] === 'capital' ? ['details' => []] + $check : $check,
                $report['checks'],
            );
        }

        return response()->json(['data' => $report]);
    }
}
