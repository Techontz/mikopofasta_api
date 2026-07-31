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

            'maritalStatus' => $this->marital_status?->value,
            'regionId' => self::id($this->region_id),
            'districtId' => self::id($this->district_id),
            'wardId' => self::id($this->ward_id),
            'streetId' => self::id($this->street_id),
            'residenceType' => $this->residence_type?->value,

            'customerCategoryId' => self::id($this->customer_category_id),
            'dynamicFormData' => $this->dynamic_form_data,
            'branchId' => (string) $this->branch_id,

            'kycStatus' => $this->kyc_status->value,
            'status' => $this->status->value,

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
