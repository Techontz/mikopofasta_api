<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Controllers\Api\V1\ApiController;
use App\Services\Customers\RequirementProfiles;
use Illuminate\Http\JsonResponse;

/**
 * GET /registration/requirements — the company's requirement profiles (CUSTOMER_MODULE_IMPLEMENTATION.md §3.3).
 */
class RegistrationRequirementController extends ApiController
{
    public function __invoke(RequirementProfiles $profiles): JsonResponse
    {
        return response()->json(['data' => $profiles->forApi($this->currentEmployee()->company_id)]);
    }
}
