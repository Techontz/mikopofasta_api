<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Actions\AssignCustomerCategoryAction;
use App\Domain\Customers\Actions\UpdateCustomerAdditionalDataAction;
use App\Domain\Customers\Actions\VerifyCustomerFaceAction;
use App\Domain\Customers\Exceptions\CustomerAlreadyRegisteredException;
use App\Domain\Customers\Exceptions\InvalidOtpException;
use App\Domain\Customers\Services\NidaRegistry;
use App\Domain\Organization\Services\BranchScopeGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\AdditionalDataRequest;
use App\Http\Requests\Customers\AssignCategoryRequest;
use App\Http\Requests\Customers\FaceVerifyRequest;
use App\Http\Requests\Customers\NidaLookupRequest;
use App\Http\Requests\Customers\NidaOtpVerifyRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * The KYC identity flow — spec §9 and §15.1.
 *
 * NIDA lookup → OTP → (registration) → face capture, plus the two
 * post-registration correction endpoints.
 */
final class KycController extends Controller
{
    public function __construct(private readonly BranchScopeGuard $guard) {}

    /**
     * POST /api/v1/customers/nida-lookup — spec §15.1.
     *
     * Resolves the identity and dispatches an OTP. Spec §9 is explicit that
     * NIDA is the source of truth: these values populate the wizard and the
     * officer does not type them.
     */
    public function lookup(NidaLookupRequest $request, NidaRegistry $nida): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $nidaNumber = (string) $request->validated('nidaNumber');

        // Refuse early rather than letting the officer complete a seven-step
        // wizard only to collide on the unique index at the end.
        if (Customer::query()->where('nida_number', $nidaNumber)->exists()) {
            throw new CustomerAlreadyRegisteredException;
        }

        return ApiResponse::data([
            'verified' => false,
            'otpSent' => true,
            'customerDraft' => $nida->lookup($nidaNumber)->toArray(),
        ]);
    }

    /**
     * POST /api/v1/customers/nida-otp-verify — spec §15.1.
     *
     * Returns the confirmed identity alongside the verification timestamp the
     * wizard carries into its final submission.
     */
    public function verifyOtp(NidaOtpVerifyRequest $request, NidaRegistry $nida): JsonResponse
    {
        $this->authorize('create', Customer::class);

        if (! $nida->verifyOtp((string) $request->validated('otp'))) {
            throw new InvalidOtpException;
        }

        return ApiResponse::data([
            'verified' => true,
            'verifiedAt' => now()->toIso8601String(),
            'customerDraft' => $nida->lookup((string) $request->validated('nidaNumber'))->toArray(),
        ]);
    }

    /**
     * POST /api/v1/customers/{customer}/additional-data — spec §15.1.
     */
    public function additionalData(
        AdditionalDataRequest $request,
        Customer $customer,
        UpdateCustomerAdditionalDataAction $action,
    ): JsonResponse {
        $this->authorize('update', $customer);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Customer::class);

        return ApiResponse::data(
            new CustomerResource($action->handle($customer, $request->validated(), $actor)),
        );
    }

    /**
     * PUT /api/v1/customers/{customer}/category — spec §15.1.
     */
    public function assignCategory(
        AssignCategoryRequest $request,
        Customer $customer,
        AssignCustomerCategoryAction $action,
    ): JsonResponse {
        $this->authorize('update', $customer);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Customer::class);

        $category = CustomerCategory::query()->findOrFail($request->validated('customerCategoryId'));

        $updated = $action->handle(
            $customer,
            $category,
            (array) $request->validated('dynamicFormData'),
            $actor,
        );

        return ApiResponse::data(new CustomerResource($updated));
    }

    /**
     * POST /api/v1/customers/{customer}/face-verify — spec §15.1.
     */
    public function faceVerify(
        FaceVerifyRequest $request,
        Customer $customer,
        VerifyCustomerFaceAction $action,
    ): JsonResponse {
        $this->authorize('update', $customer);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Customer::class);

        $capture = $request->file('capture');

        abort_unless($capture instanceof UploadedFile, Response::HTTP_UNPROCESSABLE_ENTITY);

        return ApiResponse::data(
            new CustomerResource($action->handle($customer, $capture, $request->report(), $actor)),
        );
    }
}
