<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Customers\Enums\Gender;
use App\Domain\Customers\Enums\GuarantorRelationship;
use App\Domain\Customers\Enums\MaritalStatus;
use App\Domain\Customers\Enums\ResidenceType;
use App\Domain\Customers\Services\AccountTypeRequirementResolver;
use App\Domain\Customers\Services\ExternalVerificationStatus;
use App\Models\AccountTypeRequirement;
use App\Models\CustomerCategory;
use App\Models\MasterData\ContractType;
use App\Models\MasterData\SectorCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
    /**
     * The contract type whose expiry date is mandatory.
     *
     * A code rather than an id: ids differ between environments, and a name
     * can be renamed or translated by an administrator without warning.
     */
    private const string TEMPORARY_CONTRACT_CODE = 'TEMPORARY';

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
             * Identity is entered by hand, because there is nothing to look it
             * up in.
             *
             * These four were originally `required`, which only made sense
             * while a registry could be queried. There is none — what filled
             * them was a simulator that invented an identity from a hash of the
             * number typed — so a required `nidaVerifiedAt` meant every
             * registration carried a timestamp for a check that never ran.
             *
             * They were then made optional but tied together with
             * `required_with:nidaNumber`, which inverted the problem: an
             * officer copying a National ID off the card in front of them could
             * not register the customer at all without also asserting a
             * verification. That is the defect this replaces.
             *
             * A National ID number is now what it actually is — an identity
             * DOCUMENT, captured — and carries no implication that anybody
             * checked it. The verification timestamps are accepted only from a
             * deployment that can genuinely produce them; see
             * `checkVerificationClaims` below, which is what stops a client
             * asserting a check that could not have happened.
             *
             * `faceVerifiedAt` is likewise never required here. It is stamped
             * by the face-verify endpoint that actually stores the capture,
             * which is where a liveness claim belongs.
             */
            'nidaNumber' => ['nullable', 'string', 'min:10', 'max:30', Rule::unique('customers', 'nida_number')],

            /*
             * Identity as one type plus one number — see the 2026_08_30
             * migration. `id_types` is admin-managed, so the rule checks the
             * row exists and is active rather than naming the six codes here;
             * a list this file knew by heart could not be added to without a
             * deployment.
             */
            'idTypeId' => ['nullable', 'integer', Rule::exists('id_types', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'idNumber' => ['nullable', 'string', 'max:60'],

            /* Where they serve. The cadre is checked against its parent in
               after(): `exists` alone would accept a TAMISEMI cadre under a
               different sector entirely. */
            'sectorId' => ['nullable', 'integer', Rule::exists('sectors', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'sectorCategoryId' => ['nullable', 'integer', Rule::exists('sector_categories', 'id')->whereNull('deleted_at')->where('is_active', true)],

            /* On what terms. The expiry rule depends on WHICH contract type
               was chosen, which means reading its code — done in after(). */
            'contractTypeId' => ['nullable', 'integer', Rule::exists('contract_types', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'contractExpiryDate' => ['nullable', 'date'],

            /* The private employer, from its own admin-managed list — NOT the
               sector list a public servant picks from. */
            'employerId' => ['nullable', 'integer', Rule::exists('employers', 'id')->whereNull('deleted_at')->where('is_active', true)],

            'nidaVerifiedAt' => ['nullable', 'date'],
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
            /*
             * Kept, and still accepted, for anything that already holds one —
             * the profile reads the relation when it is there. New
             * registrations send the typed names below instead; see the
             * 2026_08_26 migration for why the two lowest address levels
             * stopped being dropdowns.
             */
            'wardId' => ['nullable', 'integer', Rule::exists('wards', 'id')],
            'streetId' => ['nullable', 'integer', Rule::exists('streets', 'id')],
            'wardName' => ['nullable', 'string', 'max:120'],
            'streetName' => ['nullable', 'string', 'max:120'],
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
            /* Typed, not chosen. No list of occupations is ever complete, and
               one that is not complete forces a wrong answer. */
            'employmentType' => ['nullable', 'string', 'max:120'],
            'workType' => ['nullable', 'string', 'max:120'],

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
     * The rules the Account Type decides — the client's requirement that
     * "Account Type MUST control the subsequent registration steps".
     *
     * These cannot live in `rules()`. Which fields are mandatory depends on a
     * database row that is not known until the request's own `accountTypeId`
     * has been read, and Laravel builds the static rule array before any of
     * that. `required_if` cannot express it either: the condition is not
     * another field's value, it is what an administrator configured for that
     * value.
     *
     * BACKEND VALIDATION, NOT A SECOND COPY OF THE FRONTEND'S. The wizard
     * enforces the same profile so the officer is stopped at the step rather
     * than at submit, but this is the enforcement — a payload posted straight
     * at the API obeys exactly the same requirements.
     *
     * Face verification is deliberately NOT among them. It is the last step of
     * the workflow, it happens after the record is saved and often on another
     * device, and requiring it here is what made the whole flow impossible.
     * KycEvaluator holds that requirement instead, where it gates loan
     * eligibility rather than the save.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $profile = app(AccountTypeRequirementResolver::class)
                    ->for($this->integerOrNull('accountTypeId'));

                $this->checkAddress($validator, $profile);
                $this->checkIdentityDocument($validator, $profile);
                $this->checkMaritalStatus($validator, $profile);
                $this->checkEmployment($validator, $profile);
                $this->checkBusiness($validator, $profile);
                $this->checkBankAccount($validator, $profile);
                $this->checkCard($validator, $profile);
                $this->checkRelations($validator, $profile);
                $this->checkCategory($validator, $profile);
                $this->checkIdentityPair($validator, $profile);
                $this->checkCategoryRequirements($validator);
                $this->checkAssignedOfficer($validator);
                $this->checkVerificationClaims($validator);
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nidaNumber.unique' => 'A customer with this NIDA number is already registered.',
        ];
    }

    /**
     * A verification may only be recorded by a deployment that can perform it.
     *
     * Without this the API would accept `nidaVerifiedAt` from any client that
     * chose to send one, and the customer's record — and their KYC status, and
     * their loan eligibility — would rest on a check nothing performed. The
     * frontend sends null for all three; this is what makes that a rule rather
     * than a convention.
     *
     * It is deliberately not silent. Dropping the field would leave the caller
     * believing a verification had been stored, which is a worse failure than
     * a refusal that says exactly what happened.
     */
    private function checkVerificationClaims(Validator $validator): void
    {
        $external = app(ExternalVerificationStatus::class);

        if ($this->input('nidaVerifiedAt') !== null && ! $external->nidaAvailable()) {
            $validator->errors()->add(
                'nidaVerifiedAt',
                'A NIDA verification cannot be recorded: the registry integration is not available in this deployment. The National ID number is still captured.',
            );
        }

        if ($this->input('otpVerifiedAt') !== null && ! $external->otpAvailable()) {
            $validator->errors()->add(
                'otpVerifiedAt',
                'An SMS verification cannot be recorded: no SMS gateway is configured. The phone number is still captured.',
            );
        }
    }

    /**
     * Who the customer is registered FOR.
     *
     * The field defaults to the signed-in user and the action fills it in when
     * it is absent, so an ordinary officer never sends it. Sending somebody
     * else's id is a reassignment of the relationship — and of the portfolio
     * and commission that follow it — so it needs `customers.assign_officer`.
     *
     * Checked here rather than silently overwritten in the action: quietly
     * replacing the id would leave a supervisor believing they had assigned
     * the customer to Esther when the record says otherwise.
     */
    private function checkAssignedOfficer(Validator $validator): void
    {
        $requested = $this->integerOrNull('employeeId');
        $actor = $this->user();

        if ($requested === null || $actor === null || $requested === $actor->getKey()) {
            return;
        }

        if (! $actor->hasPermission(PermissionName::CustomersAssignOfficer)) {
            $validator->errors()->add(
                'employeeId',
                'You may only register customers under your own name.',
            );
        }
    }

    private function checkAddress(Validator $validator, AccountTypeRequirement $profile): void
    {
        if (! $profile->requires_address) {
            return;
        }

        if ($this->integerOrNull('regionId') === null) {
            $validator->errors()->add('regionId', 'Region is required.');
        }

        /* District and not ward: districts are a complete, authoritative list
           and wards are not. See the 2026_08_26 migration. */
        if ($this->integerOrNull('districtId') === null) {
            $validator->errors()->add('districtId', 'District must be selected.');
        }
    }

    /**
     * Any one of the five, because the customer chooses which they carry.
     *
     * The error is attached to the National ID field because that is the first
     * of them on the form; the message names all five so nobody concludes a
     * NIDA card is the only acceptable document.
     */
    private function checkIdentityDocument(Validator $validator, AccountTypeRequirement $profile): void
    {
        if (! $profile->requires_identity_document) {
            return;
        }

        $documents = ['nidaNumber', 'nationalIdNumber', 'voterIdNumber', 'driverLicenceNumber', 'passportNumber', 'workIdNumber'];

        foreach ($documents as $field) {
            if ($this->stringOrNull($field) !== null) {
                return;
            }
        }

        $validator->errors()->add(
            'nationalIdNumber',
            'At least one identity document is required — National ID, voter ID, driving licence, passport or work ID.',
        );
    }

    private function checkMaritalStatus(Validator $validator, AccountTypeRequirement $profile): void
    {
        if (! $profile->requires_marital_status) {
            return;
        }

        if ($this->stringOrNull('maritalStatus') === null && $this->integerOrNull('maritalStatusId') === null) {
            $validator->errors()->add('maritalStatusId', 'Marital status is required for this account type.');
        }
    }

    private function checkEmployment(Validator $validator, AccountTypeRequirement $profile): void
    {
        if (! $profile->requires_employment_details) {
            return;
        }

        if ($this->stringOrNull('employer') === null && $this->stringOrNull('placeOfEmployment') === null) {
            $validator->errors()->add('employer', 'An employer or place of employment is required for this account type.');
        }

        if ($this->stringOrNull('workType') === null && $this->stringOrNull('employmentType') === null) {
            $validator->errors()->add('workType', 'Work type or type of employment is required for this account type.');
        }

        /* Any one figure. A salaried customer gives a basic and a take-home; a
           trader gives a monthly income and neither of the others. */
        $income = ['takeHome', 'basicSalary', 'monthlyIncome'];
        $hasIncome = false;

        foreach ($income as $field) {
            if ($this->integerOrNull($field) !== null) {
                $hasIncome = true;
            }
        }

        if (! $hasIncome) {
            $validator->errors()->add('takeHome', 'An income figure is required for this account type.');
        }
    }

    private function checkBusiness(Validator $validator, AccountTypeRequirement $profile): void
    {
        if (! $profile->requires_business_details) {
            return;
        }

        if ($this->stringOrNull('businessName') === null) {
            $validator->errors()->add('businessName', 'Business name is required for this account type.');
        }

        if ($this->stringOrNull('businessType') === null) {
            $validator->errors()->add('businessType', 'Business type is required for this account type.');
        }
    }

    /**
     * A bank account, or a mobile money wallet. Many microfinance customers
     * have only the second, and refusing them an account for it would exclude
     * exactly the people this institution exists to serve.
     */
    private function checkBankAccount(Validator $validator, AccountTypeRequirement $profile): void
    {
        if (! $profile->requires_bank_account) {
            return;
        }

        $bank = $this->input('bankDetails');
        $hasBank = is_array($bank) && ($bank['accountNumber'] ?? null) !== null;

        if ($hasBank || $this->stringOrNull('walletNumber') !== null) {
            return;
        }

        $validator->errors()->add(
            'bankDetails.accountNumber',
            'A bank account or a mobile money wallet number is required for this account type.',
        );
    }

    private function checkCard(Validator $validator, AccountTypeRequirement $profile): void
    {
        if ($profile->requires_card_details && $this->stringOrNull('cardNumber') === null) {
            $validator->errors()->add('cardNumber', 'Card details are required for this account type.');
        }
    }

    private function checkRelations(Validator $validator, AccountTypeRequirement $profile): void
    {
        $guarantors = is_array($this->input('guarantors')) ? count($this->input('guarantors')) : 0;

        if ($guarantors < $profile->min_guarantors) {
            $validator->errors()->add('guarantors', sprintf(
                'At least %d guarantor%s required for this account type.',
                $profile->min_guarantors,
                $profile->min_guarantors === 1 ? ' is' : 's are',
            ));
        }

        $kin = is_array($this->input('nextOfKin')) ? count($this->input('nextOfKin')) : 0;

        if ($kin < $profile->min_next_of_kin) {
            $validator->errors()->add('nextOfKin', sprintf(
                'At least %d next of kin %s required for this account type.',
                $profile->min_next_of_kin,
                $profile->min_next_of_kin === 1 ? 'is' : 'are',
            ));
        }
    }

    private function checkCategory(Validator $validator, AccountTypeRequirement $profile): void
    {
        if ($profile->requires_customer_category && $this->integerOrNull('customerCategoryId') === null) {
            $validator->errors()->add(
                'customerCategoryId',
                'A customer category is required for this account type — it decides which loan products the customer may take.',
            );
        }
    }

    /**
     * The ID type and its number travel together or not at all.
     *
     * A number with no type is the thing this pair replaced — a digit string
     * nobody can identify. A type with no number is a branch that recorded
     * which document they asked for and not what it said.
     *
     * The pair is REQUIRED only where the account type already required an
     * identity document, so nothing that could be registered before this
     * existed becomes unregisterable. `checkIdentityDocument` still accepts
     * any of the six legacy columns, which is what keeps the older screens and
     * the drafts saved before this change working.
     */
    private function checkIdentityPair(Validator $validator, AccountTypeRequirement $profile): void
    {
        $typeId = $this->integerOrNull('idTypeId');
        $number = $this->stringOrNull('idNumber');

        if ($typeId !== null && $number === null) {
            $validator->errors()->add('idNumber', 'Enter the number shown on the identity document.');
        }

        if ($typeId === null && $number !== null) {
            $validator->errors()->add('idTypeId', 'Choose which identity document this number belongs to.');
        }

        if ($profile->requires_identity_document && $typeId === null && $number === null && ! $this->hasLegacyIdentityDocument()) {
            $validator->errors()->add('idTypeId', 'An identity document is required for this account type.');
        }
    }

    /** Whether one of the six pre-2026_08_30 ID columns carries a value. */
    private function hasLegacyIdentityDocument(): bool
    {
        foreach (['nidaNumber', 'nationalIdNumber', 'voterIdNumber', 'driverLicenceNumber', 'passportNumber', 'workIdNumber'] as $field) {
            if ($this->stringOrNull($field) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the chosen CATEGORY asks for, as opposed to what the account type
     * asks for.
     *
     * Two different questions with two different answers. The account type
     * decides the shape of the file — a loan account needs a guarantor, a
     * savings account does not. The category decides what this KIND of
     * customer must produce — a public servant has a sector and a contract, a
     * boda rider has neither. Reading both from data is what lets a new
     * category be configured rather than coded.
     */
    private function checkCategoryRequirements(Validator $validator): void
    {
        $categoryId = $this->integerOrNull('customerCategoryId');

        if ($categoryId === null) {
            return;
        }

        $category = CustomerCategory::query()->find($categoryId);

        if ($category === null) {
            return;
        }

        if ($category->requires_sector) {
            if ($this->integerOrNull('sectorId') === null) {
                $validator->errors()->add('sectorId', sprintf('A sector is required for a %s customer.', $category->name));
            }

            if ($this->integerOrNull('sectorCategoryId') === null) {
                $validator->errors()->add('sectorCategoryId', sprintf('A sector category is required for a %s customer.', $category->name));
            }
        }

        if ($category->requires_employer && $this->integerOrNull('employerId') === null) {
            $validator->errors()->add('employerId', sprintf('An employer is required for a %s customer.', $category->name));
        }

        $this->checkSectorCategoryBelongsToSector($validator);

        if ($category->requires_contract) {
            $this->checkContract($validator, $category);
        }

        if ($category->requires_salary && $this->integerOrNull('takeHome') === null) {
            $validator->errors()->add('takeHome', sprintf('A take-home salary is required for a %s customer.', $category->name));
        }
    }

    /**
     * A cadre belongs to exactly one sector, and it must be the one chosen.
     *
     * `exists:sector_categories,id` proves the row is real, not that it sits
     * under this sector — without this a form could pair a TAMISEMI sector
     * with a cadre from somewhere else and the record would read as nonsense.
     */
    private function checkSectorCategoryBelongsToSector(Validator $validator): void
    {
        $sectorId = $this->integerOrNull('sectorId');
        $categoryId = $this->integerOrNull('sectorCategoryId');

        if ($sectorId === null || $categoryId === null) {
            return;
        }

        $belongs = SectorCategory::query()
            ->whereKey($categoryId)
            ->where('sector_id', $sectorId)
            ->exists();

        if (! $belongs) {
            $validator->errors()->add('sectorCategoryId', 'That sector category does not belong to the selected sector.');
        }
    }

    /**
     * Permanent or Temporary, and what each one implies.
     *
     * The expiry date is required for a TEMPORARY contract and refused for a
     * permanent one — a permanent contract with an end date is a contradiction
     * somebody would later have to interpret. Matched on `code`, not on the
     * name: an administrator may rename or translate "Temporary" and the rule
     * has to survive that.
     */
    private function checkContract(Validator $validator, CustomerCategory $category): void
    {
        $contractTypeId = $this->integerOrNull('contractTypeId');

        if ($contractTypeId === null) {
            $validator->errors()->add('contractTypeId', sprintf('A contract type is required for a %s customer.', $category->name));

            return;
        }

        $code = ContractType::query()->whereKey($contractTypeId)->value('code');
        $expiry = $this->input('contractExpiryDate');
        $hasExpiry = is_string($expiry) && trim($expiry) !== '';

        if ($code === self::TEMPORARY_CONTRACT_CODE && ! $hasExpiry) {
            $validator->errors()->add('contractExpiryDate', 'A temporary contract needs an expiry date.');
        }

        if ($code !== self::TEMPORARY_CONTRACT_CODE && $hasExpiry) {
            $validator->errors()->add('contractExpiryDate', 'An expiry date applies only to a temporary contract.');
        }
    }

    /** Blank strings arrive from untouched text inputs and mean "not given". */
    private function stringOrNull(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function integerOrNull(string $key): ?int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
