<?php

namespace App\Http\Requests\Api\Customers;

use App\Models\Branch;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Services\AccessControl;
use App\Services\Customers\DynamicFormValidator;
use App\Services\Customers\RequirementProfiles;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /customers — the registration wizard's payload (CUSTOMER_MODULE_IMPLEMENTATION.md §4–5.4).
 * Authorization (customers.manage + branch scope) runs before validation.
 */
class StoreCustomerRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    public const RELATIONSHIPS = ['spouse', 'parent', 'sibling', 'relative', 'friend', 'colleague', 'other'];

    public function authorize(): bool
    {
        abort_unless(Gate::allows('customers.manage'), 403, 'You do not have permission to perform this action.');
        $this->assertBranchInScope($this->input('branchId'));

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->staticRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dob.required' => 'Date of birth is required.',
            'dob.before' => 'Date of birth must be in the past.',
            'nidaNumber.unique' => 'A customer with this NIDA number is already registered.',
            'customerCategoryId.integer' => 'Select a customer type from the list.',
            'customerCategoryId.exists' => 'The selected customer type is not an active customer type of this company.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['customerCategoryId' => 'customer type'];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->applyBusinessRules($validator)];
    }

    /**
     * The payload rules/messages evaluate against: the request itself for a registration.
     *
     * @return array<string, mixed>
     */
    protected function effectivePayload(): array
    {
        return $this->all();
    }

    /**
     * Whether a business-rule error on this key is reported (always, for a registration).
     */
    protected function reportsBusinessError(string $key): bool
    {
        return true;
    }

    protected function ignoredCustomerId(): ?int
    {
        return null;
    }

    /**
     * The customer type already on the record being edited (none for a registration).
     */
    protected function currentCustomerCategoryId(): ?int
    {
        return null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function staticRules(): array
    {
        $companyId = $this->user()->company_id;
        $ignore = $this->ignoredCustomerId();
        $unique = fn (string $column) => Rule::unique('customers', $column)->ignore($ignore);
        $nullableString = fn (int $max): array => ['nullable', 'string', "max:{$max}"];

        $rules = [
            'branchId' => ['required', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'employeeId' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            // Only an active type of the company; an update may keep the customer's current (since deactivated) type.
            'customerCategoryId' => ['nullable', 'integer', Rule::exists('customer_categories', 'id')->where('company_id', $companyId)->whereNull('deleted_at')
                ->where(fn ($query) => $query->where('is_active', true)->when($this->currentCustomerCategoryId(), fn ($query, int $current) => $query->orWhere('id', $current)))],
            'accountTypeId' => ['nullable', 'integer'],
            'customerTypeId' => ['nullable', 'integer'],
            'loanTypeId' => ['nullable', 'integer'],
            'firstName' => ['required', 'string', 'min:1', 'max:80'],
            'middleName' => $nullableString(80),
            'lastName' => ['required', 'string', 'min:1', 'max:80'],
            'dob' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'phone' => ['required', 'string', 'min:9', 'max:20', $unique('phone')],
            'idTypeId' => ['nullable', 'integer', Rule::exists('id_types', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'idNumber' => $nullableString(60),
            'nidaNumber' => ['nullable', 'string', 'min:10', 'max:30', $unique('nida_number')],
            'nationalIdNumber' => ['nullable', 'string', 'max:40', $unique('national_id_number')],
            'voterIdNumber' => ['nullable', 'string', 'max:40', $unique('voter_id_number')],
            'driverLicenceNumber' => ['nullable', 'string', 'max:40', $unique('driver_licence_number')],
            'passportNumber' => ['nullable', 'string', 'max:30', $unique('passport_number')],
            'workIdNumber' => $nullableString(60),
            'tinNumber' => ['nullable', 'string', 'max:30', $unique('tin_number')],
            'maritalStatusId' => ['nullable', 'integer', Rule::exists('marital_statuses', 'id')->whereNull('deleted_at')],
            'maritalStatus' => $nullableString(30),
            'dependentsCount' => ['nullable', 'integer', 'min:0', 'max:50'],
            'residenceType' => ['nullable', Rule::in(['owned', 'rented'])],
            'regionId' => ['nullable', 'integer', Rule::exists('regions', 'id')],
            'districtId' => ['nullable', 'integer', Rule::exists('districts', 'id')],
            'wardId' => ['nullable', 'integer', Rule::exists('wards', 'id')],
            'wardName' => $nullableString(120),
            'streetId' => ['nullable', 'integer'],
            'streetName' => $nullableString(120),
            'village' => $nullableString(120),
            'houseNumber' => $nullableString(60),
            'postalCode' => $nullableString(20),
            'landmark' => $nullableString(255),
            'alternativePhone' => ['nullable', 'string', 'min:9', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'nationality' => $nullableString(60),
            'placeOfEmployment' => $nullableString(150),
            'checkNumber' => $nullableString(60),
            'retirementDate' => ['nullable', 'date'],
            'basicSalary' => ['nullable', 'integer', 'min:0'],
            'takeHome' => ['nullable', 'integer', 'min:0'],
            'monthlyIncome' => ['nullable', 'integer', 'min:0'],
            'occupation' => $nullableString(150),
            'employer' => $nullableString(150),
            'employerId' => ['nullable', 'integer'],
            'department' => $nullableString(150),
            'councilNumber' => $nullableString(60),
            'employmentType' => $nullableString(60),
            'workType' => $nullableString(60),
            'employmentTypeId' => ['nullable', 'integer'],
            'workTypeId' => ['nullable', 'integer'],
            'occupationId' => ['nullable', 'integer'],
            'sectorId' => ['nullable', 'integer'],
            'sectorCategoryId' => ['nullable', 'integer'],
            'contractTypeId' => ['nullable', 'integer'],
            'contractExpiryDate' => ['nullable', 'date'],
            'businessName' => $nullableString(150),
            'businessType' => $nullableString(120),
            'businessAddress' => $nullableString(255),
            'paymentMethod' => ['nullable', Rule::in(['mno', 'bank'])],
            'mobileMoneyProviderId' => ['nullable', 'integer', Rule::exists('mobile_money_providers', 'id')->whereNull('deleted_at')],
            'mobileMoneyProvider' => $nullableString(60),
            'walletNumber' => $nullableString(30),
            'bankId' => ['nullable', 'integer', Rule::exists('banks', 'id')->whereNull('deleted_at')],
            'bankBranch' => $nullableString(100),
            'accountName' => $nullableString(150),
            'bankDetails' => ['nullable', 'array'],
            'cardLastFour' => ['nullable', 'digits:4'],
            'cardExpiryMonth' => ['nullable', 'integer', 'between:1,12'],
            'cardExpiryYear' => ['nullable', 'integer', 'between:2000,2100'],
            'registrationSource' => $nullableString(30),
            'createdDevice' => $nullableString(255),
            'dynamicFormData' => ['present', 'array'],
            'nextOfKin' => ['present', 'array'],
            'nextOfKin.*' => ['array'],
            'nextOfKin.*.name' => ['required', 'string', 'max:150'],
            'nextOfKin.*.relationship' => ['required', Rule::in(self::RELATIONSHIPS)],
            'nextOfKin.*.phone' => ['required', 'string', 'min:9', 'max:20'],
            'nextOfKin.*.address' => $nullableString(255),
            'guarantors' => ['present', 'array'],
            'guarantors.*' => ['array'],
            'guarantors.*.name' => ['required', 'string', 'max:150'],
            'guarantors.*.phone' => ['required', 'string', 'min:9', 'max:20'],
            'guarantors.*.nidaNumber' => $nullableString(30),
            'guarantors.*.relationship' => ['required', Rule::in(self::RELATIONSHIPS)],
            'guarantors.*.address' => $nullableString(255),
            'guarantors.*.occupation' => $nullableString(150),
            'nidaVerifiedAt' => ['nullable', 'date'],
            'otpVerifiedAt' => ['nullable', 'date'],
            'faceVerifiedAt' => ['nullable', 'date'],
        ];

        if (is_array($this->input('bankDetails'))) {
            $rules += [
                'bankDetails.bankName' => ['required', 'string', 'max:100'],
                'bankDetails.accountNumber' => ['required', 'string', 'max:50'],
                'bankDetails.accountName' => ['required', 'string', 'max:150'],
                'bankDetails.phoneNumber' => $nullableString(20),
                'bankDetails.checkNumber' => $nullableString(50),
            ];
        }

        return $rules;
    }

    /**
     * Requirement-profile rules (§5.2), customer-type answers (§5.3), payment method (§5.4), assigned officer
     * and external verification claims.
     */
    protected function applyBusinessRules(Validator $validator): void
    {
        $payload = $this->effectivePayload();
        $add = function (string $key, string $message) use ($validator): void {
            if ($this->reportsBusinessError($key) && ! $validator->errors()->has($key)) {
                $validator->errors()->add($key, $message);
            }
        };

        $this->applyOfficerRule($add);
        $this->applyVerificationClaims($add);

        $profile = app(RequirementProfiles::class)->resolve(
            (int) $this->user()->company_id,
            $this->integerOrNull($payload['accountTypeId'] ?? null),
            $this->integerOrNull($payload['customerCategoryId'] ?? null),
        );
        $this->applyProfileRules($payload, $profile, $add);
        $this->applyPaymentRules($payload, $add);

        $category = $this->integerOrNull($payload['customerCategoryId'] ?? null) !== null
            ? CustomerCategory::query()->where('company_id', $this->user()->company_id)->find($payload['customerCategoryId'])
            : null;
        if ($category !== null && is_array($payload['dynamicFormData'] ?? [])) {
            foreach (app(DynamicFormValidator::class)->errors($category, $payload) as $key => $message) {
                $add($key, $message);
            }
        }
    }

    /**
     * @param  callable(string, string): void  $add
     */
    protected function applyOfficerRule(callable $add): void
    {
        if (! $this->has('employeeId') || blank($this->input('employeeId'))) {
            return;
        }

        /** @var Employee $actor */
        $actor = $this->user();
        if (! in_array((int) $this->input('employeeId'), $this->allowedOfficerIds($actor), true) && ! Gate::allows('customers.assign_officer')) {
            $add('employeeId', 'Only staff who may assign officers can register a customer for another officer.');
        }
    }

    /**
     * Officers the actor may name without `customers.assign_officer`.
     *
     * @return list<int>
     */
    protected function allowedOfficerIds(Employee $actor): array
    {
        return [(int) $actor->id];
    }

    /**
     * @param  callable(string, string): void  $add
     */
    protected function applyVerificationClaims(callable $add): void
    {
        $external = app(RequirementProfiles::class)->externalVerification();

        if (filled($this->input('nidaVerifiedAt')) && ! $external['nida']['configured']) {
            $add('nidaVerifiedAt', 'NIDA verification is not configured, so a NIDA verification cannot be recorded.');
        }
        if (filled($this->input('otpVerifiedAt')) && ! $external['otp']['configured']) {
            $add('otpVerifiedAt', 'OTP verification is not configured, so an OTP verification cannot be recorded.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $profile
     * @param  callable(string, string): void  $add
     */
    protected function applyProfileRules(array $payload, array $profile, callable $add): void
    {
        $filled = fn (string ...$keys): bool => collect($keys)->contains(fn (string $key): bool => filled(Arr::get($payload, $key)));

        if ($profile['requires_address']) {
            if (! $filled('regionId')) {
                $add('regionId', 'Region is required.');
            }
            if (! $filled('districtId')) {
                $add('districtId', 'District must be selected.');
            }
        }

        if ($profile['requires_identity_document']
            && ! ($filled('idTypeId') && $filled('idNumber'))
            && ! $filled('nidaNumber', 'nationalIdNumber', 'voterIdNumber', 'driverLicenceNumber', 'passportNumber', 'workIdNumber')) {
            $add('idTypeId', 'An identity document is required — choose the ID type and enter the number shown on it.');
        }

        if ($profile['requires_marital_status'] && ! $filled('maritalStatusId', 'maritalStatus')) {
            $add('maritalStatusId', 'Marital status is required for this account type.');
        }

        if ($profile['requires_customer_category'] && ! $filled('customerCategoryId')) {
            $add('customerCategoryId', 'A customer type is required for this account type — it decides which loan products the customer may take.');
        }

        if ($profile['requires_employment_details']) {
            if (! $filled('employer', 'employerId', 'placeOfEmployment')) {
                $add('employer', 'An employer or place of employment is required for this account type.');
            }
            if (! $filled('workType', 'workTypeId', 'employmentType', 'employmentTypeId')) {
                $add('workType', 'Work type or type of employment is required for this account type.');
            }
            if (! $filled('takeHome', 'basicSalary', 'monthlyIncome')) {
                $add('takeHome', 'An income figure is required for this account type.');
            }
        }

        if ($profile['requires_business_details']) {
            if (! $filled('businessName')) {
                $add('businessName', 'Business name is required for this account type.');
            }
            if (! $filled('businessType')) {
                $add('businessType', 'Business type is required for this account type.');
            }
        }

        if ($profile['requires_bank_account'] && ! $filled('bankDetails.accountNumber', 'walletNumber')) {
            $add('bankDetails.accountNumber', 'A bank account or a mobile money wallet number is required for this account type.');
        }

        if ($profile['requires_card_details'] && ! $filled('cardNumber', 'cardLastFour')) {
            $add('cardNumber', 'Card details are required for this account type.');
        }

        $guarantors = is_array($payload['guarantors'] ?? null) ? count($payload['guarantors']) : 0;
        if ($profile['min_guarantors'] > 0 && $guarantors < $profile['min_guarantors']) {
            $count = $profile['min_guarantors'];
            $add('guarantors', $count === 1 ? "At least {$count} guarantor is required for this account type." : "At least {$count} guarantors are required for this account type.");
        }

        $kin = is_array($payload['nextOfKin'] ?? null) ? count($payload['nextOfKin']) : 0;
        if ($profile['min_next_of_kin'] > 0 && $kin < $profile['min_next_of_kin']) {
            $count = $profile['min_next_of_kin'];
            $add('nextOfKin', $count === 1 ? "At least {$count} next of kin is required for this account type." : "At least {$count} next of kin are required for this account type.");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(string, string): void  $add
     */
    protected function applyPaymentRules(array $payload, callable $add): void
    {
        $method = $payload['paymentMethod'] ?? null;

        if ($method === 'mno') {
            if (blank($payload['mobileMoneyProviderId'] ?? null)) {
                $add('mobileMoneyProviderId', 'Choose the mobile money provider.');
            }
            if (blank($payload['walletNumber'] ?? null)) {
                $add('walletNumber', 'Enter the number the wallet is registered on.');
            }
        }

        if ($method === 'bank') {
            if (blank($payload['bankId'] ?? null)) {
                $add('bankId', 'Choose the bank.');
            }
            if (blank(Arr::get($payload, 'bankDetails.accountNumber'))) {
                $add('bankDetails.accountNumber', 'Enter the account number.');
            }
        }
    }

    protected function assertBranchInScope(mixed $branchId): void
    {
        if (! is_numeric($branchId)) {
            return;
        }

        /** @var Employee $actor */
        $actor = $this->user();
        $visible = app(AccessControl::class)->branchIds($actor);
        $inCompany = Branch::whereKey((int) $branchId)->where('company_id', $actor->company_id)->exists();

        if ($inCompany && $visible !== null && ! in_array((int) $branchId, $visible, true)) {
            abort(403, 'You do not have access to this branch.');
        }
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
