<?php

namespace App\Services\Customers;

use App\Models\AccountTypeRequirement;
use Illuminate\Database\Eloquent\Collection;

/**
 * Registration requirement profiles (CUSTOMER_MODULE_SPEC.md §2.4): a baseline row plus optional rows per
 * account type and / or customer type. A resolved profile merges every applicable row: flags OR together,
 * minimums take the maximum, and the most specific guidance wins.
 */
class RequirementProfiles
{
    /**
     * The reference baseline, also used when a company has no baseline row on file.
     *
     * @var array<string, bool|int|string|null>
     */
    public const BASELINE = [
        'requires_employment_details' => false,
        'requires_business_details' => false,
        'requires_bank_account' => false,
        'requires_card_details' => false,
        'requires_customer_category' => false,
        'requires_marital_status' => false,
        'requires_address' => true,
        'requires_identity_document' => true,
        'requires_category_documents' => false,
        'requires_face_verification' => true,
        'requires_nida_verification' => false,
        'requires_otp_verification' => false,
        'min_guarantors' => 0,
        'min_next_of_kin' => 0,
        'category_documents_enforced_from' => null,
        'guidance' => 'Baseline requirements. Choosing an account type may add to these.',
    ];

    /**
     * Resolve the profile for a registration.
     *
     * @return array{requires_employment_details: bool, requires_business_details: bool, requires_bank_account: bool, requires_card_details: bool, requires_customer_category: bool, requires_marital_status: bool, requires_address: bool, requires_identity_document: bool, requires_category_documents: bool, requires_face_verification: bool, requires_nida_verification: bool, requires_otp_verification: bool, min_guarantors: int, min_next_of_kin: int, category_documents_enforced_from: string|null, guidance: string|null}
     */
    public function resolve(int $companyId, ?int $accountTypeId = null, ?int $customerCategoryId = null): array
    {
        $rows = AccountTypeRequirement::query()
            ->where('company_id', $companyId)
            ->where(fn ($query) => $query->whereNull('account_type_id')->when($accountTypeId !== null, fn ($query) => $query->orWhere('account_type_id', $accountTypeId)))
            ->where(fn ($query) => $query->whereNull('customer_category_id')->when($customerCategoryId !== null, fn ($query) => $query->orWhere('customer_category_id', $customerCategoryId)))
            ->get();

        return $this->merge($rows);
    }

    /**
     * Response body of GET /registration/requirements (CUSTOMER_MODULE_IMPLEMENTATION.md §3.3).
     *
     * @return array{profiles: list<array<string, mixed>>, externalVerification: array<string, mixed>}
     */
    public function forApi(int $companyId): array
    {
        $accountTypes = AccountTypeRequirement::query()
            ->where('company_id', $companyId)
            ->whereNotNull('account_type_id')
            ->whereNull('customer_category_id')
            ->orderBy('account_type_id')
            ->get()
            ->unique('account_type_id');

        $profiles = [$this->toApi(null, null, true, $this->resolve($companyId))];
        foreach ($accountTypes as $row) {
            $profiles[] = $this->toApi((int) $row->account_type_id, $row->account_type_name, false, $this->resolve($companyId, (int) $row->account_type_id));
        }

        return ['profiles' => $profiles, 'externalVerification' => $this->externalVerification()];
    }

    /**
     * Whether the NIDA and OTP integrations are configured. A verification claim is refused when they are not.
     *
     * @return array{nida: array{configured: bool}, otp: array{configured: bool}}
     */
    public function externalVerification(): array
    {
        $nidaConfigured = config('integrations.nida.driver') !== 'test' && filled(config('integrations.nida.base_url'));

        return [
            'nida' => ['configured' => $nidaConfigured],
            'otp' => ['configured' => $nidaConfigured],
        ];
    }

    /**
     * @param  Collection<int, AccountTypeRequirement>  $rows
     * @return array<string, mixed>
     */
    private function merge(Collection $rows): array
    {
        $baseline = $rows->first(fn (AccountTypeRequirement $row): bool => $row->account_type_id === null && $row->customer_category_id === null);
        $profile = $baseline === null ? self::BASELINE : $this->rowValues($baseline);

        $specific = $rows
            ->reject(fn (AccountTypeRequirement $row): bool => $row === $baseline)
            ->sortBy(fn (AccountTypeRequirement $row): int => ($row->account_type_id !== null ? 1 : 0) + ($row->customer_category_id !== null ? 2 : 0));

        foreach ($specific as $row) {
            $values = $this->rowValues($row);
            foreach (AccountTypeRequirement::FLAGS as $flag) {
                $profile[$flag] = $profile[$flag] || $values[$flag];
            }
            $profile['min_guarantors'] = max($profile['min_guarantors'], $values['min_guarantors']);
            $profile['min_next_of_kin'] = max($profile['min_next_of_kin'], $values['min_next_of_kin']);
            $profile['category_documents_enforced_from'] = collect([$profile['category_documents_enforced_from'], $values['category_documents_enforced_from']])->filter()->min();
            $profile['guidance'] = filled($values['guidance']) ? $values['guidance'] : $profile['guidance'];
        }

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowValues(AccountTypeRequirement $row): array
    {
        $values = [];
        foreach (AccountTypeRequirement::FLAGS as $flag) {
            $values[$flag] = (bool) $row->{$flag};
        }

        return $values + [
            'min_guarantors' => (int) $row->min_guarantors,
            'min_next_of_kin' => (int) $row->min_next_of_kin,
            'category_documents_enforced_from' => $row->category_documents_enforced_from?->toDateString(),
            'guidance' => $row->guidance,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function toApi(?int $accountTypeId, ?string $accountTypeName, bool $isDefault, array $profile): array
    {
        return [
            'accountTypeId' => $accountTypeId,
            'accountTypeName' => $accountTypeName,
            'isDefault' => $isDefault,
            'requiresEmploymentDetails' => $profile['requires_employment_details'],
            'requiresBusinessDetails' => $profile['requires_business_details'],
            'requiresBankAccount' => $profile['requires_bank_account'],
            'requiresCardDetails' => $profile['requires_card_details'],
            'minGuarantors' => $profile['min_guarantors'],
            'minNextOfKin' => $profile['min_next_of_kin'],
            'requiresCustomerCategory' => $profile['requires_customer_category'],
            'requiresMaritalStatus' => $profile['requires_marital_status'],
            'requiresAddress' => $profile['requires_address'],
            'requiresIdentityDocument' => $profile['requires_identity_document'],
            'requiresCategoryDocuments' => $profile['requires_category_documents'],
            'categoryDocumentsEnforcedFrom' => $profile['category_documents_enforced_from'],
            'requiresFaceVerification' => $profile['requires_face_verification'],
            'requiresNidaVerification' => $profile['requires_nida_verification'],
            'requiresOtpVerification' => $profile['requires_otp_verification'],
            'guidance' => $profile['guidance'],
        ];
    }
}
