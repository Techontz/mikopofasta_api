<?php

declare(strict_types=1);

namespace App\Domain\Customers\Support;

/**
 * The registration fields that have a real column, and the key an administrator
 * names to reach one.
 *
 * WHY THIS EXISTS. §26 of the requirement: a configurable form must not add a
 * database column every time an administrator adds a field, and it must not
 * lose the ability to query the values the business actually reports on. Those
 * two pull in opposite directions, and this map is where they meet.
 *
 * A category's field definition may carry `storesIn`, naming one of these. The
 * answer is then written to the customer COLUMN it names — indexed, typed,
 * joinable, visible to every report and search that already reads it. A field
 * WITHOUT `storesIn` lands in `customers.dynamic_form_data`, the JSON column
 * built for exactly that. So an administrator who adds "Land Size" to a farmers
 * type gets JSON and no migration, and one who adds "Basic Salary" and points
 * it at `basicSalary` gets `customers.basic_salary` and the payroll report keeps
 * working — with no code change for either.
 *
 * IT IS DECLARED, NEVER INFERRED FROM THE KEY. An earlier draft treated a key
 * that happened to match a column as an instruction to write that column, which
 * is wrong twice over: it silently RELOCATES the answers of every category
 * already configured with such a key — a customer type asking `business_type`
 * has been filing that in JSON for as long as it has existed, and its customers
 * hold it there — and it lets an administrator overwrite a real column by
 * accident, simply by naming a field the same thing. Storage location is a
 * decision, so it is made explicitly and shown on the administration screen.
 *
 * IT IS AN ALLOWLIST. `firstName`, `phone`, `branchId`, `status` and every
 * other field are absent on purpose: a configured field must not be able to
 * overwrite the customer's identity, their branch, or anything the registration
 * form asks for in its own right. Adding an entry here is a deliberate decision
 * made in a code review, which is the correct amount of friction for widening
 * what configuration may write.
 */
final class StructuredRegistrationField
{
    /**
     * Customer column => the registration payload key that fills it, which is
     * also the value an administrator selects as `storesIn`.
     *
     * @var array<string, string>
     */
    public const array MAP = [
        // Personal detail.
        'nickname' => 'nickname',
        'marital_status_id' => 'maritalStatusId',
        'dependents_count' => 'dependentsCount',
        'residence_type' => 'residenceType',
        'alternative_phone' => 'alternativePhone',
        'email' => 'email',
        'nationality' => 'nationality',
        'tin_number' => 'tinNumber',

        // Address detail below the district. The two chosen levels are the
        // registration form's own and are deliberately not configurable.
        'village' => 'village',
        'house_number' => 'houseNumber',
        'postal_code' => 'postalCode',
        'landmark' => 'landmark',

        // Where they work, and on what terms.
        'sector_id' => 'sectorId',
        'sector_category_id' => 'sectorCategoryId',
        'employer_id' => 'employerId',
        'employer' => 'employer',
        'place_of_employment' => 'placeOfEmployment',
        'department' => 'department',
        'council_number' => 'councilNumber',
        'check_number' => 'checkNumber',
        'occupation' => 'occupation',
        'occupation_id' => 'occupationId',
        'work_type' => 'workType',
        'work_type_id' => 'workTypeId',
        'employment_type' => 'employmentType',
        'employment_type_id' => 'employmentTypeId',
        'contract_type_id' => 'contractTypeId',
        'contract_expiry_date' => 'contractExpiryDate',
        'retirement_date' => 'retirementDate',

        // What they earn.
        'basic_salary' => 'basicSalary',
        'take_home' => 'takeHome',
        'monthly_income' => 'monthlyIncome',

        // Their business, for a category that lends against one.
        'business_name' => 'businessName',
        'business_type' => 'businessType',
        'business_address' => 'businessAddress',

        // Where money goes.
        'bank_id' => 'bankId',
        'bank_branch' => 'bankBranch',
        'mobile_money_provider_id' => 'mobileMoneyProviderId',
        'wallet_number' => 'walletNumber',
    ];

    /**
     * Every value `storesIn` may take.
     *
     * @return list<string>
     */
    public static function targets(): array
    {
        return array_values(self::MAP);
    }

    /**
     * The payload key a field writes to, or null for the JSON store.
     *
     * A `storesIn` naming something that is not on the allowlist is treated as
     * absent rather than as an error: the request validates it on the way in,
     * and a schema that predates a since-removed entry should keep filing its
     * answers rather than failing every registration under that category.
     *
     * @param array<string, mixed> $field
     */
    public static function target(array $field): ?string
    {
        $storesIn = $field['storesIn'] ?? null;

        if (! is_string($storesIn) || $storesIn === '') {
            return null;
        }

        return in_array($storesIn, self::targets(), true) ? $storesIn : null;
    }

    /**
     * A registration-shaped payload built from what a customer already holds.
     *
     * Every key in the map is also the customer COLUMN it names, so this reads
     * the record and hands back the same shape a registration would have sent.
     * It is what lets a category be assigned to an EXISTING customer and still
     * be judged against its required fields: without it the validator would be
     * given nothing for those fields and would report every one of them missing
     * on a customer who has had the values on file for months.
     *
     * @param array<string, mixed> $attributes the customer's attributes
     * @return array<string, mixed>
     */
    public static function payloadFrom(array $attributes): array
    {
        $payload = [];

        foreach (self::MAP as $column => $payloadKey) {
            if (array_key_exists($column, $attributes)) {
                $payload[$payloadKey] = $attributes[$column];
            }
        }

        return $payload;
    }
}
