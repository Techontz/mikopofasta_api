<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Customers\Enums\KycStatus;
use App\Models\Customer;

/**
 * The KYC checklist — backend spec §9, mirroring the frontend's
 * getKycChecklist() in types/customer.ts.
 *
 * All five items must hold for `kyc_status` to be `completed`, and only a
 * completed customer may be attached to a loan application. This is the single
 * place the rule is expressed: every write path that could affect it calls
 * `refresh()` rather than setting the column directly, so the status can never
 * drift from the facts that justify it.
 */
final class KycEvaluator
{
    /**
     * @return array{
     *     nidaVerified: bool, otpVerified: bool, faceVerified: bool,
     *     additionalDataComplete: bool, categoryAssigned: bool
     * }
     */
    public function checklist(Customer $customer): array
    {
        return [
            'nidaVerified' => $customer->nida_verified_at !== null,
            'otpVerified' => $customer->otp_verified_at !== null,
            'faceVerified' => $customer->face_verified_at !== null,

            /*
             * "Additional data" is the frontend's exact definition: bank
             * details on file, a marital status, and a region. Not the whole
             * address — the wizard makes only region and residence type
             * required, since a rural customer may have no ward or street.
             */
            'additionalDataComplete' => $customer->bankDetails()->exists()
                && $customer->marital_status !== null
                && $customer->region_id !== null,

            'categoryAssigned' => $customer->customer_category_id !== null,
        ];
    }

    public function isComplete(Customer $customer): bool
    {
        foreach ($this->checklist($customer) as $satisfied) {
            if (! $satisfied) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recomputes and persists `kyc_status`.
     *
     * Deliberately two-way: KYC can regress. Reassigning a customer to no
     * category, or removing their bank details, genuinely makes them
     * incomplete again, and silently leaving the status at `completed` would
     * let an ineligible customer through the loan gate.
     */
    public function refresh(Customer $customer): Customer
    {
        $status = $this->isComplete($customer) ? KycStatus::Completed : KycStatus::Incomplete;

        if ($customer->kyc_status !== $status) {
            $customer->update(['kyc_status' => $status]);
        }

        return $customer;
    }

    /**
     * Required documents from the customer's category that have not been
     * uploaded yet. Surfaced on the profile ("Missing required documents: …").
     *
     * Note this does NOT feed the checklist above: the frontend treats missing
     * documents as a warning rather than a blocker, and inventing a stricter
     * rule here would silently make customers loan-ineligible in a way the UI
     * never explains.
     *
     * @return list<string>
     */
    public function missingDocuments(Customer $customer): array
    {
        $category = $customer->category;

        if ($category === null) {
            return [];
        }

        $uploaded = $customer->documents->pluck('document_type')->all();

        return array_values(array_diff($category->required_documents, $uploaded));
    }
}
