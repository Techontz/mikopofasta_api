<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Customers\Services\KycDocumentStorage;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Matches `CustomerSchema` in the frontend's types/customer.ts.
 *
 * `photoPath` is NOT the stored path. The liveness capture is biometric data
 * on the private disk; what goes out is a signed, expiring URL to the download
 * endpoint, or null. Same treatment as customer documents.
 *
 * @mixin Customer
 */
final class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'customerNumber' => $this->customer_number,
            'nidaNumber' => $this->nida_number,

            'firstName' => $this->first_name,
            'middleName' => $this->middle_name,
            'lastName' => $this->last_name,
            'fullName' => $this->fullName(),
            'dob' => $this->dob->toDateString(),
            'gender' => $this->gender->value,
            'phone' => $this->phone,

            'photoPath' => $this->photo_path === null
                ? null
                : URL::temporarySignedRoute(
                    'api.v1.customers.photo',
                    now()->addMinutes(KycDocumentStorage::URL_TTL_MINUTES),
                    ['customer' => $this->id],
                ),

            'nidaVerifiedAt' => $this->nida_verified_at?->toIso8601String(),
            'otpVerifiedAt' => $this->otp_verified_at?->toIso8601String(),
            'faceVerifiedAt' => $this->face_verified_at?->toIso8601String(),

            /*
             * The active scan's summary, denormalised onto the customer so a
             * list of two hundred names can show "verified, 92%" without two
             * hundred joins. The full report — per-check results, device,
             * IP, history — comes from /customers/{id}/face-scans.
             */
            'faceScanId' => self::id($this->active_face_scan_id),
            'faceScanStatus' => $this->face_scan_status?->value,
            'faceScanQuality' => $this->face_scan_quality,
            'faceScanVersion' => $this->face_scan_version,
            'faceScannedAt' => $this->face_scanned_at?->toIso8601String(),
            'faceScannedById' => self::id($this->face_scanned_by),
            'faceScannedByName' => $this->whenLoaded(
                'faceScanOperator',
                fn (): ?string => $this->faceScanOperator?->name,
            ),

            'maritalStatus' => $this->marital_status?->value,
            'regionId' => self::id($this->region_id),
            'districtId' => self::id($this->district_id),
            'wardId' => self::id($this->ward_id),
            'streetId' => self::id($this->street_id),
            /* The typed levels. Backfilled from the id columns by the
               2026_08_26 migration, so display code reads these alone. */
            'wardName' => $this->ward_name,
            'streetName' => $this->street_name,
            'residenceType' => $this->residence_type?->value,

            // The KYC detail block — see the 2026_08_02 migration for why these
            // are columns rather than dynamic_form_data.
            'alternativePhone' => $this->alternative_phone,
            'email' => $this->email,
            'nationality' => $this->nationality,
            'nationalIdNumber' => $this->national_id_number,
            'tinNumber' => $this->tin_number,
            'passportNumber' => $this->passport_number,

            'village' => $this->village,
            'houseNumber' => $this->house_number,
            'postalCode' => $this->postal_code,
            'landmark' => $this->landmark,

            'occupation' => $this->occupation,
            'employer' => $this->employer,
            'monthlyIncome' => $this->monthly_income,
            'employmentType' => $this->employment_type,
            'workType' => $this->work_type,

            'businessName' => $this->business_name,
            'businessType' => $this->business_type,
            'businessAddress' => $this->business_address,

            'bankName' => $this->bank_name,
            'bankBranch' => $this->bank_branch,
            'accountName' => $this->account_name,
            'accountNumber' => $this->account_number,
            'mobileMoneyProvider' => $this->mobile_money_provider,
            'walletNumber' => $this->wallet_number,

            'registrationSource' => $this->registration_source,

            // Legacy registration form, so the profile can show and edit them.
            'employeeId' => self::id($this->employee_id),
            'loanTypeId' => self::id($this->loan_type_id),
            'customerTypeId' => self::id($this->customer_type_id),
            'accountTypeId' => self::id($this->account_type_id),
            'workTypeId' => self::id($this->work_type_id),
            'employmentTypeId' => self::id($this->employment_type_id),
            'occupationId' => self::id($this->occupation_id),
            'maritalStatusId' => self::id($this->marital_status_id),
            'bankId' => self::id($this->bank_id),
            'mobileMoneyProviderId' => self::id($this->mobile_money_provider_id),
            'nickname' => $this->nickname,
            'department' => $this->department,
            'councilNumber' => $this->council_number,
            'placeOfEmployment' => $this->place_of_employment,
            'retirementDate' => $this->retirement_date?->toDateString(),
            'dependentsCount' => $this->dependents_count,
            'basicSalary' => $this->basic_salary,
            'takeHome' => $this->take_home,
            'checkNumber' => $this->check_number,
            'voterIdNumber' => $this->voter_id_number,
            'driverLicenceNumber' => $this->driver_licence_number,
            'workIdNumber' => $this->work_id_number,
            'cardLastFour' => $this->card_last_four,
            'cardExpiryMonth' => $this->card_expiry_month,
            'cardExpiryYear' => $this->card_expiry_year,

            'customerCategoryId' => self::id($this->customer_category_id),
            'dynamicFormData' => $this->dynamic_form_data,
            'branchId' => (string) $this->branch_id,

            'kycStatus' => $this->kyc_status->value,
            'status' => $this->status->value,
            /* Why this customer is suspended or active, on the same terms as
               `rejectionReason` below. */
            'statusReason' => $this->status_reason,
            'statusRemarks' => $this->status_remarks,
            'statusChangedAt' => $this->status_changed_at?->toIso8601String(),
            'statusChangedById' => self::id($this->status_changed_by),

            'approvalStatus' => $this->approval_status->value,
            'approvedBy' => self::id($this->approved_by),
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'rejectionReason' => $this->rejection_reason,

            'createdBy' => self::id($this->created_by),
            'createdAt' => $this->created_at?->toIso8601String(),
            'deletedAt' => $this->deleted_at?->toIso8601String(),

            // Display names for the list view, only when eager-loaded.
            'branchName' => $this->whenLoaded('branch', fn (): ?string => $this->branch?->name),
            'categoryName' => $this->whenLoaded('category', fn (): ?string => $this->category?->name),
        ];
    }

    private static function id(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
