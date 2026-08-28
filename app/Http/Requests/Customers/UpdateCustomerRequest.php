<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Domain\Customers\Enums\Gender;
use App\Domain\Customers\Enums\ResidenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /customers/{customer} — the profile's edit contract.
 *
 * Registration captures a customer once; this is how the record is corrected
 * afterwards, which is the ordinary case. A phone number is mistyped, an
 * employer changes, a customer finally produces the TIN they did not have on
 * the day. Before this endpoint existed only six fields could be amended
 * (`additional-data`), so everything else entered during registration was
 * permanent — a spelling mistake in a surname was unfixable.
 *
 * EVERY RULE IS `sometimes`. A profile section saves on its own, so the request
 * carries only the fields that section owns; absent means "not being edited",
 * never "clear it". Sending `null` explicitly does clear a nullable field.
 *
 * Uniqueness ignores the row being edited, or saving a form without touching
 * the phone number would fail on the customer's own number.
 *
 * NOT EDITABLE HERE, on purpose:
 *   - `customerNumber` — the identifier the institution and its documents use.
 *   - the KYC verification timestamps — set by the checks that produced them,
 *     never by a form, which is the whole lesson of the simulated-NIDA flow.
 *   - `status` / `approvalStatus` — those have their own endpoints because
 *     they carry reasons and audit consequences an edit form does not.
 */
final class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('customer')?->getKey();

        return [
            // ---- identity ----
            'firstName' => ['sometimes', 'string', 'min:1', 'max:80'],
            'middleName' => ['sometimes', 'nullable', 'string', 'max:80'],
            'lastName' => ['sometimes', 'string', 'min:1', 'max:80'],
            'nickname' => ['sometimes', 'nullable', 'string', 'max:80'],
            'dob' => ['sometimes', 'date', 'before:today'],
            'gender' => ['sometimes', 'string', Rule::in(Gender::values())],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:60'],

            // ---- contact ----
            'phone' => ['sometimes', 'string', 'min:9', 'max:20', Rule::unique('customers', 'phone')->ignore($id)],
            'alternativePhone' => ['sometimes', 'nullable', 'string', 'min:9', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],

            // ---- identity documents ----
            'nationalIdNumber' => ['sometimes', 'nullable', 'string', 'max:40', Rule::unique('customers', 'national_id_number')->ignore($id)],
            'voterIdNumber' => ['sometimes', 'nullable', 'string', 'max:40', Rule::unique('customers', 'voter_id_number')->ignore($id)],
            'driverLicenceNumber' => ['sometimes', 'nullable', 'string', 'max:40', Rule::unique('customers', 'driver_licence_number')->ignore($id)],
            'passportNumber' => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('customers', 'passport_number')->ignore($id)],
            'tinNumber' => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('customers', 'tin_number')->ignore($id)],
            'workIdNumber' => ['sometimes', 'nullable', 'string', 'max:60'],

            // ---- placement ----
            'branchId' => ['sometimes', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'employeeId' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'customerCategoryId' => ['sometimes', 'nullable', 'integer', Rule::exists('customer_categories', 'id')->whereNull('deleted_at')],
            'loanTypeId' => ['sometimes', 'nullable', 'integer', Rule::exists('loan_types', 'id')->whereNull('deleted_at')],
            'customerTypeId' => ['sometimes', 'nullable', 'integer', Rule::exists('customer_types', 'id')->whereNull('deleted_at')],
            'accountTypeId' => ['sometimes', 'nullable', 'integer', Rule::exists('account_types', 'id')->whereNull('deleted_at')],
            'workTypeId' => ['sometimes', 'nullable', 'integer', Rule::exists('work_types', 'id')->whereNull('deleted_at')],
            'employmentTypeId' => ['sometimes', 'nullable', 'integer', Rule::exists('employment_types', 'id')->whereNull('deleted_at')],
            'occupationId' => ['sometimes', 'nullable', 'integer', Rule::exists('occupations', 'id')->whereNull('deleted_at')],
            'maritalStatusId' => ['sometimes', 'nullable', 'integer', Rule::exists('marital_statuses', 'id')->whereNull('deleted_at')],
            'bankId' => ['sometimes', 'nullable', 'integer', Rule::exists('banks', 'id')->whereNull('deleted_at')],
            'mobileMoneyProviderId' => ['sometimes', 'nullable', 'integer', Rule::exists('mobile_money_providers', 'id')->whereNull('deleted_at')],

            // ---- address ----
            'regionId' => ['sometimes', 'nullable', 'integer', Rule::exists('regions', 'id')],
            'districtId' => ['sometimes', 'nullable', 'integer', Rule::exists('districts', 'id')],
            /* The ids stay editable for records that already hold one; the
               typed names are what the form writes. See the 2026_08_26
               migration for why the two lowest levels stopped being lists. */
            'wardId' => ['sometimes', 'nullable', 'integer', Rule::exists('wards', 'id')],
            'streetId' => ['sometimes', 'nullable', 'integer', Rule::exists('streets', 'id')],
            'wardName' => ['sometimes', 'nullable', 'string', 'max:120'],
            'streetName' => ['sometimes', 'nullable', 'string', 'max:120'],
            'village' => ['sometimes', 'nullable', 'string', 'max:120'],
            'houseNumber' => ['sometimes', 'nullable', 'string', 'max:40'],
            'postalCode' => ['sometimes', 'nullable', 'string', 'max:20'],
            'landmark' => ['sometimes', 'nullable', 'string', 'max:180'],
            'residenceType' => ['sometimes', 'nullable', 'string', Rule::in(ResidenceType::values())],

            // ---- employment & business ----
            'occupation' => ['sometimes', 'nullable', 'string', 'max:120'],
            'employer' => ['sometimes', 'nullable', 'string', 'max:150'],
            /* Free text, both of them. The `*_id` references above stay for
               records captured before the change. */
            'workType' => ['sometimes', 'nullable', 'string', 'max:120'],
            'employmentType' => ['sometimes', 'nullable', 'string', 'max:120'],
            'department' => ['sometimes', 'nullable', 'string', 'max:120'],
            'councilNumber' => ['sometimes', 'nullable', 'string', 'max:60'],
            'placeOfEmployment' => ['sometimes', 'nullable', 'string', 'max:150'],
            'retirementDate' => ['sometimes', 'nullable', 'date'],
            'dependentsCount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:50'],
            'monthlyIncome' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'basicSalary' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'takeHome' => ['sometimes', 'nullable', 'integer', 'min:0'],

            /* Identity, sector and contract — 2026_08_30. Existence and active
               state are checked; the sector/cadre pairing and the temporary
               expiry rule are enforced at registration, where the category
               that demands them is part of the same payload. */
            'idTypeId' => ['sometimes', 'nullable', 'integer', Rule::exists('id_types', 'id')->whereNull('deleted_at')],
            'idNumber' => ['sometimes', 'nullable', 'string', 'max:60'],
            'sectorId' => ['sometimes', 'nullable', 'integer', Rule::exists('sectors', 'id')->whereNull('deleted_at')],
            'sectorCategoryId' => ['sometimes', 'nullable', 'integer', Rule::exists('sector_categories', 'id')->whereNull('deleted_at')],
            'contractTypeId' => ['sometimes', 'nullable', 'integer', Rule::exists('contract_types', 'id')->whereNull('deleted_at')],
            'contractExpiryDate' => ['sometimes', 'nullable', 'date'],
            'employerId' => ['sometimes', 'nullable', 'integer', Rule::exists('employers', 'id')->whereNull('deleted_at')],
            'businessName' => ['sometimes', 'nullable', 'string', 'max:150'],
            'businessType' => ['sometimes', 'nullable', 'string', 'max:120'],
            'businessAddress' => ['sometimes', 'nullable', 'string', 'max:255'],

            // ---- money ----
            'bankName' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bankBranch' => ['sometimes', 'nullable', 'string', 'max:100'],
            'accountName' => ['sometimes', 'nullable', 'string', 'max:150'],
            'accountNumber' => ['sometimes', 'nullable', 'string', 'max:50'],
            'checkNumber' => ['sometimes', 'nullable', 'string', 'max:60'],
            'mobileMoneyProvider' => ['sometimes', 'nullable', 'string', 'max:60'],
            'walletNumber' => ['sometimes', 'nullable', 'string', 'max:30'],
            /* Full number in, last four stored — same rule as registration.
               There is no column for a PAN, so there is nothing to leak. */
            'cardNumber' => ['sometimes', 'nullable', 'string', 'min:12', 'max:19'],
            'cardExpiryMonth' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'cardExpiryYear' => ['sometimes', 'nullable', 'integer', 'min:2020', 'max:2099'],

            'updatedDevice' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
