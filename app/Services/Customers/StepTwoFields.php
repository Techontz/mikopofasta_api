<?php

namespace App\Services\Customers;

use App\Models\CustomerCategory;

/**
 * Composes the Step 2 ("Customer Details") field list of a customer type exactly per customer-types.json
 * compositionRules (CUSTOMER_TYPE_REQUIREMENTS.md §2): configured fields first, then the optional standard
 * block chosen by sector (and by the requirement profile), minus omitted / duplicated / collected-elsewhere fields.
 */
class StepTwoFields
{
    /**
     * Payload columns collected on Step 1 or in the Account Number section, never rendered on Step 2.
     *
     * @var list<string>
     */
    public const COLLECTED_ELSEWHERE = [
        'dependentsCount', 'alternativePhone', 'email', 'nationality', 'maritalStatusId', 'residenceType',
        'bankId', 'bankBranch', 'mobileMoneyProviderId', 'walletNumber',
    ];

    /**
     * Standard employment block (Sector / Cadre, Employer and Contract fields are conditional, see below).
     *
     * @var list<array<string, mixed>>
     */
    public const EMPLOYMENT = [
        ['key' => 'place_of_employment', 'label' => 'Place of Employment', 'type' => 'text', 'required' => false, 'storesIn' => 'placeOfEmployment'],
        ['key' => 'check_number', 'label' => 'Check Number', 'type' => 'text', 'required' => false, 'storesIn' => 'checkNumber', 'helpText' => 'The payroll check number, where the employer issues one.'],
        ['key' => 'basic_salary', 'label' => 'Basic Salary', 'type' => 'currency', 'required' => false, 'storesIn' => 'basicSalary'],
        ['key' => 'take_home', 'label' => 'Take Home', 'type' => 'currency', 'required' => false, 'storesIn' => 'takeHome'],
        ['key' => 'monthly_income', 'label' => 'Monthly Income', 'type' => 'currency', 'required' => false, 'storesIn' => 'monthlyIncome'],
        ['key' => 'retirement_date', 'label' => 'Date of Retirement', 'type' => 'date', 'required' => false, 'storesIn' => 'retirementDate', 'helpText' => 'Where the employer sets one.'],
    ];

    /**
     * @var list<array<string, mixed>>
     */
    public const EMPLOYMENT_WHEN_REQUIRES_SECTOR = [
        ['key' => 'sector_id', 'label' => 'Sector', 'type' => 'select', 'required' => false, 'storesIn' => 'sectorId', 'dataSource' => 'sectors'],
        ['key' => 'sector_category_id', 'label' => 'Cadre', 'type' => 'select', 'required' => false, 'storesIn' => 'sectorCategoryId', 'dataSource' => 'sector-categories', 'dependsOn' => 'sector_id'],
    ];

    /**
     * @var list<array<string, mixed>>
     */
    public const EMPLOYMENT_WHEN_REQUIRES_EMPLOYER = [
        ['key' => 'employer_id', 'label' => 'Employer', 'type' => 'select', 'required' => false, 'storesIn' => 'employerId', 'dataSource' => 'employers'],
    ];

    /**
     * @var list<array<string, mixed>>
     */
    public const EMPLOYMENT_WHEN_REQUIRES_CONTRACT = [
        ['key' => 'contract_type_id', 'label' => 'Contract Type', 'type' => 'select', 'required' => false, 'storesIn' => 'contractTypeId', 'dataSource' => 'contract-types'],
        ['key' => 'contract_expiry_date', 'label' => 'Contract Expiry Date', 'type' => 'date', 'required' => false, 'storesIn' => 'contractExpiryDate', 'dependsOn' => 'contract_type_id', 'requiredWhen' => ['field' => 'contract_type_id', 'equals' => ['TEMPORARY']]],
    ];

    /**
     * @var list<array<string, mixed>>
     */
    public const BUSINESS = [
        ['key' => 'business_name', 'label' => 'Business Name', 'type' => 'text', 'required' => false, 'storesIn' => 'businessName'],
        ['key' => 'business_type', 'label' => 'Business Type', 'type' => 'text', 'required' => false, 'storesIn' => 'businessType', 'placeholder' => 'Retail, transport, agriculture…'],
        ['key' => 'business_address', 'label' => 'Business Address', 'type' => 'text', 'required' => false, 'storesIn' => 'businessAddress', 'fullWidth' => true],
        ['key' => 'tin_number', 'label' => 'TIN Number (Optional)', 'type' => 'text', 'required' => false, 'storesIn' => 'tinNumber', 'helpText' => 'Where the business is registered for tax.'],
        ['key' => 'monthly_income', 'label' => 'Monthly Income', 'type' => 'currency', 'required' => false, 'storesIn' => 'monthlyIncome'],
    ];

    /**
     * @var list<array<string, mixed>>
     */
    public const OTHER = [
        ['key' => 'monthly_income', 'label' => 'Monthly Income', 'type' => 'currency', 'required' => false, 'storesIn' => 'monthlyIncome'],
    ];

    /**
     * Compose the Step 2 fields of a customer type. Each field carries `origin` = configured | standard.
     *
     * @param  array<string, mixed>  $profile  resolved requirement profile (RequirementProfiles::resolve), snake_case flags
     * @return list<array<string, mixed>>
     */
    public function compose(CustomerCategory $type, array $profile = []): array
    {
        $configured = array_values(array_filter(
            (array) ($type->dynamic_form_schema ?? []),
            fn ($field): bool => is_array($field) && isset($field['key']),
        ));

        $configuredKeys = array_column($configured, 'key');
        $configuredColumns = array_values(array_filter(array_column($configured, 'storesIn')));
        $omitted = (array) ($type->omitted_standard_fields ?? []);

        $fields = [];
        foreach ($configured as $field) {
            if (isset($field['storesIn']) && in_array($field['storesIn'], self::COLLECTED_ELSEWHERE, true)) {
                continue;
            }
            $fields[] = $field + ['origin' => 'configured'];
        }

        $seen = [];
        foreach ($this->standardBlock($type, $profile) as $field) {
            if (isset($seen[$field['key']])
                || in_array($field['key'], $omitted, true)
                || in_array($field['key'], $configuredKeys, true)
                || in_array($field['storesIn'], $configuredColumns, true)
                || in_array($field['storesIn'], self::COLLECTED_ELSEWHERE, true)) {
                continue;
            }
            $seen[$field['key']] = true;
            $fields[] = ['required' => false] + $field + ['origin' => 'standard'];
        }

        return $fields;
    }

    /**
     * Payload names (`storesIn`) of the standard fields the type omits, unless a configured field writes the same column
     * (e.g. Mwanafunzi wa Chuo "Boom" → monthlyIncome). Answers to these are not part of the type and are not stored.
     * A block the requirement profile demands (employment / business details) keeps its columns, because the profile
     * rules read them.
     *
     * @param  array<string, mixed>  $profile  resolved requirement profile (RequirementProfiles::resolve), snake_case flags
     * @return list<string>
     */
    public function omittedColumns(CustomerCategory $type, array $profile = []): array
    {
        $omitted = (array) ($type->omitted_standard_fields ?? []);
        $configuredColumns = array_column(array_filter((array) ($type->dynamic_form_schema ?? []), 'is_array'), 'storesIn');
        $kept = array_column([
            ...(empty($profile['requires_employment_details']) ? [] : self::EMPLOYMENT),
            ...(empty($profile['requires_business_details']) ? [] : self::BUSINESS),
        ], 'storesIn');

        $columns = [];
        foreach ([...self::EMPLOYMENT, ...self::BUSINESS] as $field) {
            if (in_array($field['key'], $omitted, true)
                && ! in_array($field['storesIn'], $configuredColumns, true)
                && ! in_array($field['storesIn'], $kept, true)) {
                $columns[$field['storesIn']] = true;
            }
        }

        return array_keys($columns);
    }

    /**
     * The standard fields in order: the sector's block, then any block the requirement profile adds.
     *
     * @param  array<string, mixed>  $profile
     * @return list<array<string, mixed>>
     */
    private function standardBlock(CustomerCategory $type, array $profile): array
    {
        $blocks = match ($type->sector) {
            'employment' => ['employment'],
            'business' => ['business'],
            default => ['other'],
        };
        if (! empty($profile['requires_employment_details']) && ! in_array('employment', $blocks, true)) {
            $blocks[] = 'employment';
        }
        if (! empty($profile['requires_business_details']) && ! in_array('business', $blocks, true)) {
            $blocks[] = 'business';
        }

        $fields = [];
        foreach ($blocks as $block) {
            array_push($fields, ...match ($block) {
                'employment' => $this->employmentBlock($type),
                'business' => self::BUSINESS,
                default => self::OTHER,
            });
        }

        return $fields;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function employmentBlock(CustomerCategory $type): array
    {
        $fields = [];
        if ($type->requires_sector) {
            array_push($fields, ...self::EMPLOYMENT_WHEN_REQUIRES_SECTOR);
        }
        if ($type->requires_employer) {
            array_push($fields, ...self::EMPLOYMENT_WHEN_REQUIRES_EMPLOYER);
        }
        foreach (self::EMPLOYMENT as $field) {
            $fields[] = $field;
            if ($field['key'] === 'check_number' && $type->requires_contract) {
                array_push($fields, ...self::EMPLOYMENT_WHEN_REQUIRES_CONTRACT);
            }
        }

        return $fields;
    }
}
