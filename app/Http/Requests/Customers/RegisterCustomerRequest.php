<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Domain\Customers\Enums\Gender;
use App\Domain\Customers\Enums\GuarantorRelationship;
use App\Domain\Customers\Enums\MaritalStatus;
use App\Domain\Customers\Enums\ResidenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Implements the frontend's RegisterCustomerInputSchema (types/customer.ts)
 * rule for rule, including the wizard's per-step minimums:
 * nidaNumber min 10, firstName/lastName min 1, phone min 9.
 *
 * The three verification timestamps are required, not optional: spec §9 makes
 * NIDA lookup, OTP and liveness the gate on registration, and the wizard
 * refuses to advance without them. Accepting a payload without them here would
 * let a client register an unverified identity by skipping the UI.
 */
final class RegisterCustomerRequest extends FormRequest
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
        return [
            /*
             * Identity is entered by hand until the NIDA registry exists.
             *
             * These four were all `required`, which only made sense while a
             * registry could be looked up. There is none — what filled them was
             * a simulator that invented an identity from a hash — so a required
             * `nidaVerifiedAt` meant every registration carried a timestamp for
             * a check that never happened.
             *
             * They are now optional and travel together: supply the National ID
             * and its verification timestamp, or supply neither. A
             * `NidaIdentityProvider` will send all four again the day the
             * integration lands, and this rule already accepts that.
             *
             * `faceVerifiedAt` stays required-with-nothing but is validated by
             * the face-verify endpoint that actually stores the capture, which
             * is where a liveness claim belongs.
             */
            'nidaNumber' => ['nullable', 'string', 'min:10', 'max:30', Rule::unique('customers', 'nida_number')],

            'nidaVerifiedAt' => ['nullable', 'date', 'required_with:nidaNumber'],
            'otpVerifiedAt' => ['nullable', 'date'],
            'faceVerifiedAt' => ['nullable', 'date'],

            'firstName' => ['required', 'string', 'min:1', 'max:80'],
            'middleName' => ['nullable', 'string', 'max:80'],
            'lastName' => ['required', 'string', 'min:1', 'max:80'],
            'dob' => ['required', 'date', 'before:today'],
            'gender' => ['required', 'string', Rule::in(Gender::values())],
            'phone' => ['required', 'string', 'min:9', 'max:20', Rule::unique('customers', 'phone')],

            'maritalStatus' => ['nullable', 'string', Rule::in(MaritalStatus::values())],

            'regionId' => ['nullable', 'integer', Rule::exists('regions', 'id')],
            'districtId' => ['nullable', 'integer', Rule::exists('districts', 'id')],
            'wardId' => ['nullable', 'integer', Rule::exists('wards', 'id')],
            'streetId' => ['nullable', 'integer', Rule::exists('streets', 'id')],
            'residenceType' => ['nullable', 'string', Rule::in(ResidenceType::values())],

            /*
             * The KYC detail block. All optional: a microfinance customer
             * frequently has no email, no TIN and no passport, and a required
             * rule would either block their registration or be satisfied with a
             * placeholder. Completeness is the KYC evaluator's judgement, not
             * the validator's.
             *
             * Uniqueness is enforced where a duplicate would be a real problem —
             * two customers cannot share a TIN, a passport or a national ID —
             * and left off the rest, where duplicates are legitimate (a family
             * sharing one email, a business phone on several records).
             */
            'alternativePhone' => ['nullable', 'string', 'min:9', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'nationality' => ['nullable', 'string', 'max:60'],
            'nationalIdNumber' => ['nullable', 'string', 'max:40', Rule::unique('customers', 'national_id_number')],
            'tinNumber' => ['nullable', 'string', 'max:30', Rule::unique('customers', 'tin_number')],
            'passportNumber' => ['nullable', 'string', 'max:30', Rule::unique('customers', 'passport_number')],

            'village' => ['nullable', 'string', 'max:120'],
            'houseNumber' => ['nullable', 'string', 'max:40'],
            'postalCode' => ['nullable', 'string', 'max:20'],
            'landmark' => ['nullable', 'string', 'max:180'],

            'occupation' => ['nullable', 'string', 'max:120'],
            'employer' => ['nullable', 'string', 'max:150'],
            'monthlyIncome' => ['nullable', 'integer', 'min:0'],
            'employmentType' => ['nullable', 'string', 'max:40'],

            'businessName' => ['nullable', 'string', 'max:150'],
            'businessType' => ['nullable', 'string', 'max:120'],
            'businessAddress' => ['nullable', 'string', 'max:255'],

            'bankBranch' => ['nullable', 'string', 'max:100'],
            'mobileMoneyProvider' => ['nullable', 'string', 'max:60'],
            'walletNumber' => ['nullable', 'string', 'max:30'],

            // Where the record came from. Defaulted server-side rather than
            // trusted from the client for anything but the device string.
            'createdDevice' => ['nullable', 'string', 'max:255'],

            // ---- legacy step 1: Basic Information ----
            'employeeId' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'loanTypeId' => ['nullable', 'integer', Rule::exists('loan_types', 'id')->whereNull('deleted_at')],
            'customerTypeId' => ['nullable', 'integer', Rule::exists('customer_types', 'id')->whereNull('deleted_at')],

            // ---- legacy step 2: Aditinal Detail ----
            'nickname' => ['nullable', 'string', 'max:80'],
            'accountTypeId' => ['nullable', 'integer', Rule::exists('account_types', 'id')->whereNull('deleted_at')],
            'workTypeId' => ['nullable', 'integer', Rule::exists('work_types', 'id')->whereNull('deleted_at')],
            'employmentTypeId' => ['nullable', 'integer', Rule::exists('employment_types', 'id')->whereNull('deleted_at')],
            'occupationId' => ['nullable', 'integer', Rule::exists('occupations', 'id')->whereNull('deleted_at')],
            'maritalStatusId' => ['nullable', 'integer', Rule::exists('marital_statuses', 'id')->whereNull('deleted_at')],
            'department' => ['nullable', 'string', 'max:120'],
            'councilNumber' => ['nullable', 'string', 'max:60'],
            'placeOfEmployment' => ['nullable', 'string', 'max:150'],
            'retirementDate' => ['nullable', 'date'],
            'dependentsCount' => ['nullable', 'integer', 'min:0', 'max:50'],
            'basicSalary' => ['nullable', 'integer', 'min:0'],
            'takeHome' => ['nullable', 'integer', 'min:0'],
            'checkNumber' => ['nullable', 'string', 'max:60'],

            // ---- legacy step 3: Passport size & Bank Detail ----
            'voterIdNumber' => ['nullable', 'string', 'max:40', Rule::unique('customers', 'voter_id_number')],
            'driverLicenceNumber' => ['nullable', 'string', 'max:40', Rule::unique('customers', 'driver_licence_number')],
            'workIdNumber' => ['nullable', 'string', 'max:60'],
            /* The legacy step-3 "Account name" box. Also reachable inside
               `bankDetails`; whichever arrives wins, top-level last. */
            'accountName' => ['nullable', 'string', 'max:150'],
            'bankId' => ['nullable', 'integer', Rule::exists('banks', 'id')->whereNull('deleted_at')],
            'mobileMoneyProviderId' => ['nullable', 'integer', Rule::exists('mobile_money_providers', 'id')->whereNull('deleted_at')],
            /*
             * The card number arrives full and is reduced to its last four in
             * the action before anything is written. It is validated for shape
             * here only so an obvious typo is caught; it is never stored.
             */
            'cardNumber' => ['nullable', 'string', 'min:12', 'max:19'],
            'cardExpiryMonth' => ['nullable', 'integer', 'min:1', 'max:12'],
            'cardExpiryYear' => ['nullable', 'integer', 'min:2020', 'max:2099'],

            'branchId' => ['required', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            /*
             * Optional, because the legacy registration form does not collect
             * it — it collects "Types of customer" (BINAFSI / KIKUNDI /
             * TAASISI), which is separate master data. Requiring a category the
             * form has no field for made every legacy-faithful registration
             * impossible.
             *
             * A category still drives the dynamic KYC questions when one is
             * assigned; without it there are no extra questions to validate,
             * which is what `dynamicFormData` falling back to an empty array
             * below means. Assign one later from the customer's profile.
             */
            'customerCategoryId' => ['nullable', 'integer', Rule::exists('customer_categories', 'id')->whereNull('deleted_at')],

            // Contents are validated against the category's schema by
            // DynamicFormValidator — the rules are data, so no static rule can
            // express them.
            'dynamicFormData' => ['present', 'array'],

            'bankDetails' => ['nullable', 'array'],
            'bankDetails.bankName' => ['required_with:bankDetails', 'string', 'max:100'],
            'bankDetails.accountNumber' => ['required_with:bankDetails', 'string', 'max:50'],
            'bankDetails.accountName' => ['required_with:bankDetails', 'string', 'max:150'],
            'bankDetails.phoneNumber' => ['nullable', 'string', 'max:20'],
            'bankDetails.checkNumber' => ['nullable', 'string', 'max:50'],

            'guarantors' => ['present', 'array'],
            'guarantors.*.name' => ['required', 'string', 'max:150'],
            'guarantors.*.phone' => ['required', 'string', 'min:9', 'max:20'],
            'guarantors.*.nidaNumber' => ['nullable', 'string', 'max:30'],
            'guarantors.*.relationship' => ['required', 'string', Rule::in(GuarantorRelationship::values())],
            'guarantors.*.address' => ['nullable', 'string', 'max:255'],
            'guarantors.*.occupation' => ['nullable', 'string', 'max:150'],

            'nextOfKin' => ['present', 'array'],
            'nextOfKin.*.name' => ['required', 'string', 'max:150'],
            'nextOfKin.*.relationship' => ['required', 'string', Rule::in(GuarantorRelationship::values())],
            'nextOfKin.*.phone' => ['required', 'string', 'min:9', 'max:20'],
            'nextOfKin.*.address' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nidaNumber.unique' => 'A customer with this NIDA number is already registered.',
            'nidaVerifiedAt.required' => 'NIDA verification must be completed before registration.',
            'otpVerifiedAt.required' => 'OTP verification must be completed before registration.',
            'faceVerifiedAt.required' => 'Face verification must be completed before registration.',
        ];
    }
}
