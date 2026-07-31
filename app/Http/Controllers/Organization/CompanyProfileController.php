<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Domain\Organization\Actions\UpdateCompanyProfileAction;
use App\Domain\Organization\DTOs\CompanyProfileData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UpdateCompanyProfileRequest;
use App\Http\Resources\CompanyProfileResource;
use App\Models\CompanyProfile;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The singleton company profile.
 *
 * No store or destroy: exactly one row exists, seeded with the organization.
 */
final class CompanyProfileController extends Controller
{
    /**
     * GET /api/v1/company-profile
     */
    public function show(Request $request): JsonResponse
    {
        $profile = CompanyProfile::current();

        $this->authorize('view', $profile);

        return ApiResponse::data(new CompanyProfileResource($profile));
    }

    /**
     * PUT /api/v1/company-profile
     */
    public function update(UpdateCompanyProfileRequest $request, UpdateCompanyProfileAction $action): JsonResponse
    {
        $this->authorize('update', CompanyProfile::current());

        $profile = $action->handle(
            CompanyProfileData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(new CompanyProfileResource($profile));
    }
}
