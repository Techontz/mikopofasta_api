<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AccountTypeRequirement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One account type's requirement profile.
 *
 * @mixin AccountTypeRequirement
 */
final class AccountTypeRequirementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /*
             * Null for the default profile, and the client relies on that: it
             * keys the map by account type id and reads the null entry when
             * nothing is chosen yet, which is the state the wizard opens in.
             */
            'accountTypeId' => $this->account_type_id === null ? null : (string) $this->account_type_id,
            'accountTypeName' => $this->whenLoaded(
                'accountType',
                fn (): ?string => $this->accountType?->name,
            ),
            'isDefault' => $this->isDefault(),

            'requiresEmploymentDetails' => $this->requires_employment_details,
            'requiresBusinessDetails' => $this->requires_business_details,
            'requiresBankAccount' => $this->requires_bank_account,
            'requiresCardDetails' => $this->requires_card_details,
            'minGuarantors' => $this->min_guarantors,
            'minNextOfKin' => $this->min_next_of_kin,
            'requiresCustomerCategory' => $this->requires_customer_category,
            'requiresMaritalStatus' => $this->requires_marital_status,
            'requiresAddress' => $this->requires_address,
            'requiresIdentityDocument' => $this->requires_identity_document,
            'requiresFaceVerification' => $this->requires_face_verification,
            'requiresNidaVerification' => $this->requires_nida_verification,
            'requiresOtpVerification' => $this->requires_otp_verification,
            'guidance' => $this->guidance,
        ];
    }
}
