<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Customers\Services\AccountTypeRequirementResolver;
use App\Domain\Customers\Services\ExternalVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccountTypeRequirementResource;
use App\Models\AccountTypeRequirement;
use App\Models\MasterData\AccountType;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What registration requires, and what this deployment can verify.
 *
 * The registration wizard reads this once when it opens and decides from it
 * which steps to show, which fields to mark required, and what to say about
 * NIDA and SMS. It does NOT decide any of that for itself: the same profiles
 * are read by RegisterCustomerRequest when the form is submitted and by
 * KycEvaluator when the customer's completeness is judged, so all three agree
 * by construction rather than by three developers remembering to.
 *
 * READS are open to any authenticated user who may register a customer.
 * WRITES need `admin.org_settings` — the same permission that gates the
 * account types themselves, since a requirement profile is configuration of
 * one.
 */
final class RegistrationRequirementsController extends Controller
{
    /**
     * GET /api/v1/registration/requirements
     */
    public function index(
        AccountTypeRequirementResolver $resolver,
        ExternalVerificationStatus $external,
    ): JsonResponse {
        return ApiResponse::data([
            'profiles' => AccountTypeRequirementResource::collection($resolver->all()),
            /*
             * Sent alongside, not inferred. A client that had to work out
             * "requiresNidaVerification is true but is it possible?" would be
             * re-deriving the rule KycEvaluator already applies, and the two
             * would disagree the first time either changed.
             */
            'externalVerification' => $external->summary(),
        ]);
    }

    /**
     * PUT /api/v1/registration/requirements/{accountType}
     *
     * The whole profile is replaced, not patched. A half-sent profile would
     * leave the unnamed flags at whatever they were, which for a screen of
     * checkboxes means unticking one and having it silently stay ticked.
     */
    public function update(Request $request, AccountType $accountType): JsonResponse
    {
        $actor = $this->actor($request);

        abort_unless($actor->hasPermission(PermissionName::AdminOrgSettings), 403);

        $data = $request->validate([
            'requiresEmploymentDetails' => ['required', 'boolean'],
            'requiresBusinessDetails' => ['required', 'boolean'],
            'requiresBankAccount' => ['required', 'boolean'],
            'requiresCardDetails' => ['required', 'boolean'],
            'minGuarantors' => ['required', 'integer', 'min:0', 'max:10'],
            'minNextOfKin' => ['required', 'integer', 'min:0', 'max:10'],
            'requiresCustomerCategory' => ['required', 'boolean'],
            'requiresMaritalStatus' => ['required', 'boolean'],
            'requiresAddress' => ['required', 'boolean'],
            'requiresIdentityDocument' => ['required', 'boolean'],
            'requiresFaceVerification' => ['required', 'boolean'],
            /*
             * Accepted, and honoured — but see KycEvaluator: a requirement the
             * deployment cannot perform is reported as blocked rather than
             * quietly enforced, so turning this on before the integration
             * exists stalls customers visibly instead of silently.
             */
            'requiresNidaVerification' => ['required', 'boolean'],
            'requiresOtpVerification' => ['required', 'boolean'],
            'guidance' => ['nullable', 'string', 'max:255'],
        ]);

        $profile = AccountTypeRequirement::query()->updateOrCreate(
            ['account_type_id' => $accountType->getKey()],
            [
                'requires_employment_details' => $data['requiresEmploymentDetails'],
                'requires_business_details' => $data['requiresBusinessDetails'],
                'requires_bank_account' => $data['requiresBankAccount'],
                'requires_card_details' => $data['requiresCardDetails'],
                'min_guarantors' => $data['minGuarantors'],
                'min_next_of_kin' => $data['minNextOfKin'],
                'requires_customer_category' => $data['requiresCustomerCategory'],
                'requires_marital_status' => $data['requiresMaritalStatus'],
                'requires_address' => $data['requiresAddress'],
                'requires_identity_document' => $data['requiresIdentityDocument'],
                'requires_face_verification' => $data['requiresFaceVerification'],
                'requires_nida_verification' => $data['requiresNidaVerification'],
                'requires_otp_verification' => $data['requiresOtpVerification'],
                'guidance' => $data['guidance'] ?? null,
                'updated_by' => $actor->getKey(),
            ],
        );

        return ApiResponse::data(
            new AccountTypeRequirementResource($profile->load('accountType')),
        );
    }
}
