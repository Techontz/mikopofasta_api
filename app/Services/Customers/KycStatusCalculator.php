<?php

namespace App\Services\Customers;

use App\Models\Customer;
use Carbon\CarbonImmutable;

/**
 * Server-side KYC derivation (CUSTOMER_MODULE_IMPLEMENTATION.md §5.5): identity document, phone, address,
 * face verification, category documents and NIDA / OTP per the resolved requirement profile.
 * `completed` only when every required item holds.
 */
class KycStatusCalculator
{
    public const COMPLETED = 'completed';

    public const INCOMPLETE = 'incomplete';

    /**
     * Legacy identity numbers that satisfy the identity-document requirement on their own.
     *
     * @var list<string>
     */
    public const LEGACY_IDENTITY_COLUMNS = ['nida_number', 'national_id_number', 'voter_id_number', 'driver_licence_number', 'passport_number', 'work_id_number'];

    public function __construct(private RequirementProfiles $profiles) {}

    /**
     * @return list<array{key: string, label: string, required: bool, complete: bool}>
     */
    public function checklist(Customer $customer): array
    {
        $profile = $this->profiles->resolve(
            (int) $customer->company_id,
            $customer->account_type_id !== null ? (int) $customer->account_type_id : null,
            $customer->customer_category_id !== null ? (int) $customer->customer_category_id : null,
        );

        return [
            $this->item('identity_document', 'Identity document', (bool) $profile['requires_identity_document'], $this->hasIdentityDocument($customer)),
            $this->item('phone', 'Phone number', true, filled($customer->phone)),
            $this->item('address', 'Address (region and district)', (bool) $profile['requires_address'], $customer->region_id !== null && $customer->district_id !== null),
            $this->item('face_verification', 'Face verification', (bool) $profile['requires_face_verification'], $customer->face_verified_at !== null),
            $this->item('category_documents', 'Customer type documents', $this->categoryDocumentsRequired($customer, $profile), $this->hasCategoryDocuments($customer)),
            $this->item('nida_verification', 'NIDA verification', (bool) $profile['requires_nida_verification'], $customer->nida_verified_at !== null),
            $this->item('otp_verification', 'OTP verification', (bool) $profile['requires_otp_verification'], $customer->otp_verified_at !== null),
        ];
    }

    public function status(Customer $customer): string
    {
        $outstanding = collect($this->checklist($customer))->contains(fn (array $item): bool => $item['required'] && ! $item['complete']);

        return $outstanding ? self::INCOMPLETE : self::COMPLETED;
    }

    /**
     * Recompute and store the customer's KYC status. Returns the new status.
     */
    public function refresh(Customer $customer): string
    {
        $customer->unsetRelation('documents');
        $status = $this->status($customer);

        if ($customer->kyc_status !== $status) {
            $customer->forceFill(['kyc_status' => $status])->save();
        }

        return $status;
    }

    public function hasIdentityDocument(Customer $customer): bool
    {
        if ($customer->id_type_id !== null && filled($customer->id_number)) {
            return true;
        }

        return collect(self::LEGACY_IDENTITY_COLUMNS)->contains(fn (string $column): bool => filled($customer->{$column}));
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function categoryDocumentsRequired(Customer $customer, array $profile): bool
    {
        if (! $profile['requires_category_documents'] || $customer->customer_category_id === null) {
            return false;
        }

        $enforcedFrom = $profile['category_documents_enforced_from'];

        return $enforcedFrom === null || $customer->created_at === null || CarbonImmutable::parse($customer->created_at)->startOfDay()->gte(CarbonImmutable::parse($enforcedFrom));
    }

    private function hasCategoryDocuments(Customer $customer): bool
    {
        $required = collect($customer->customerCategory?->required_documents ?? []);

        return $required->diff($customer->documents()->pluck('document_type'))->isEmpty();
    }

    /**
     * @return array{key: string, label: string, required: bool, complete: bool}
     */
    private function item(string $key, string $label, bool $required, bool $complete): array
    {
        return ['key' => $key, 'label' => $label, 'required' => $required, 'complete' => $complete];
    }
}
