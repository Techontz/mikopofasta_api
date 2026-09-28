<?php

namespace App\Http\Resources\Api\V1\Customers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer resource (CUSTOMER_MODULE_IMPLEMENTATION.md §6): every captured value, camelCase.
 * `status` is the account status (active / suspended / frozen); `loanStatus` is the loan lifecycle column.
 *
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $date = fn ($value): ?string => $value?->toDateString();
        $time = fn ($value): ?string => $value?->toIso8601String();
        $int = fn ($value): ?int => $value === null ? null : (int) $value;
        $bankDetail = $this->relationLoaded('bankDetail') ? $this->bankDetail : null;

        return [
            'id' => $this->id,
            'customerNumber' => $this->customer_number,
            'customerCode' => $this->customer_code,
            'nidaNumber' => $this->nida_number,
            'idTypeId' => $int($this->id_type_id),
            'idTypeName' => $this->whenLoaded('idType', fn () => $this->idType?->name, null),
            'idNumber' => $this->id_number,
            'firstName' => $this->first_name,
            'middleName' => $this->middle_name,
            'lastName' => $this->last_name,
            'fullName' => $this->full_name,
            'dob' => $date($this->date_of_birth),
            'age' => $this->date_of_birth?->age,
            'gender' => $this->gender,
            'phone' => $this->phone,
            'photoPath' => $this->photo_path,
            'photoUrl' => $this->photo_path ? "customers/{$this->id}/photo" : null,

            'nidaVerifiedAt' => $time($this->nida_verified_at),
            'otpVerifiedAt' => $time($this->otp_verified_at),
            'faceVerifiedAt' => $time($this->face_verified_at),
            'faceScanId' => $int($this->active_face_scan_id),
            'faceScanStatus' => $this->face_scan_status,
            'faceScanQuality' => $int($this->face_scan_quality),
            'faceScanVersion' => $this->face_scan_version,
            'faceScannedAt' => $time($this->face_scanned_at),
            'faceScannedById' => $int($this->face_scanned_by),
            'faceScannedByName' => $this->whenLoaded('faceScannedBy', fn () => $this->faceScannedBy?->full_name, null),

            'maritalStatus' => $this->marital_status,
            'maritalStatusId' => $int($this->marital_status_id),
            'regionId' => $int($this->region_id),
            'regionName' => $this->whenLoaded('region', fn () => $this->region?->name, null),
            'districtId' => $int($this->district_id),
            'districtName' => $this->district,
            'wardId' => $int($this->ward_id),
            'streetId' => $int($this->street_id),
            'wardName' => $this->ward_name,
            'streetName' => $this->street_name,
            'residenceType' => $this->residence_type,

            'alternativePhone' => $this->alternative_phone,
            'email' => $this->email,
            'nationality' => $this->nationality,
            'nationalIdNumber' => $this->national_id_number,
            'tinNumber' => $this->tin_number,
            'passportNumber' => $this->passport_number,
            'voterIdNumber' => $this->voter_id_number,
            'driverLicenceNumber' => $this->driver_licence_number,
            'workIdNumber' => $this->work_id_number,

            'village' => $this->village,
            'houseNumber' => $this->house_number,
            'postalCode' => $this->postal_code,
            'landmark' => $this->landmark,

            'occupation' => $this->occupation,
            'employer' => $this->employer,
            'monthlyIncome' => $this->monthly_income === null ? null : (int) round((float) $this->monthly_income),
            'employmentType' => $this->employment_type,
            'workType' => $this->work_type,
            'placeOfEmployment' => $this->place_of_employment,
            'retirementDate' => $date($this->retirement_date),
            'dependentsCount' => $int($this->dependents),
            'basicSalary' => $int($this->basic_salary),
            'takeHome' => $int($this->take_home),
            'checkNumber' => $this->check_number,
            'department' => $this->department,
            'councilNumber' => $this->council_number,

            'businessName' => $this->business_name,
            'businessType' => $this->business_type,
            'businessAddress' => $this->business_address,

            'bankName' => $this->bank_name,
            'bankBranch' => $this->bank_branch,
            'accountName' => $this->account_name,
            'accountNumber' => $this->account_number,
            'bankId' => $int($this->bank_id),
            'mobileMoneyProvider' => $this->mobile_money_provider,
            'mobileMoneyProviderId' => $int($this->mobile_money_provider_id),
            'walletNumber' => $this->wallet_number,
            'paymentMethod' => $this->payment_method,
            'bankDetails' => $bankDetail ? [
                'bankName' => $bankDetail->bank_name,
                'accountNumber' => $bankDetail->account_number,
                'accountName' => $bankDetail->account_name,
                'phoneNumber' => $bankDetail->phone,
                'checkNumber' => $bankDetail->check_number,
            ] : null,

            'sectorId' => $int($this->sector_id),
            'sectorName' => null,
            'sectorCategoryId' => $int($this->sector_category_id),
            'sectorCategoryName' => null,
            'contractTypeId' => $int($this->contract_type_id),
            'contractTypeName' => null,
            'contractExpiryDate' => $date($this->contract_expiry_date),
            'employerId' => $int($this->employer_id),
            'employerRecordName' => null,
            'occupationId' => $int($this->occupation_id),
            'workTypeId' => $int($this->work_type_id),
            'employmentTypeId' => $int($this->employment_type_id),

            'cardLastFour' => $this->card_last_four,
            'cardExpiryMonth' => $int($this->card_expiry_month),
            'cardExpiryYear' => $int($this->card_expiry_year),

            'customerCategoryId' => $int($this->customer_category_id),
            'categoryName' => $this->whenLoaded('customerCategory', fn () => $this->customerCategory?->name, null),
            'dynamicFormData' => (object) ($this->dynamic_form_data ?? []),

            'branchId' => $int($this->branch_id),
            'branchName' => $this->whenLoaded('branch', fn () => $this->branch?->name, null),
            'employeeId' => $int($this->employee_id),
            'employeeName' => $this->whenLoaded('employee', fn () => $this->employee?->full_name, null),
            'groupId' => $int($this->group_id),
            'groupName' => $this->whenLoaded('group', fn () => $this->group?->name, null),
            'registrationSource' => $this->registration_source,
            'accountTypeId' => $int($this->account_type_id),
            'customerTypeId' => $int($this->customer_type_id),
            'loanTypeId' => $int($this->loan_type_id),
            'nickname' => $this->nickname,

            'kycStatus' => $this->kyc_status,
            'status' => $this->account_status,
            'loanStatus' => $this->status,
            'statusReason' => $this->status_reason,
            'statusRemarks' => $this->status_remarks,
            'statusChangedAt' => $time($this->status_changed_at),
            'statusChangedById' => $int($this->status_changed_by),

            'approvalStatus' => $this->approval_status,
            'approvedBy' => $int($this->approved_by),
            'approvedAt' => $time($this->approved_at),
            'rejectionReason' => $this->rejection_reason,
            'isMarked' => (bool) $this->is_marked,

            'createdBy' => $int($this->created_by),
            'createdAt' => $time($this->created_at),
            'deletedAt' => $time($this->deleted_at),

            'nextOfKin' => NextOfKinResource::collection($this->whenLoaded('nextOfKins')),
            'guarantors' => GuarantorResource::collection($this->whenLoaded('guarantors')),
            'documents' => CustomerDocumentResource::collection($this->whenLoaded('documents')),
        ];
    }
}
