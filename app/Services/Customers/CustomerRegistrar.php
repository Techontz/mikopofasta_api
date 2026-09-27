<?php

namespace App\Services\Customers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\District;
use App\Models\Employee;
use App\Models\MasterData\Bank;
use App\Models\MasterData\MaritalStatus;
use App\Models\MasterData\MobileMoneyProvider;
use App\Models\Ward;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Registration and update persistence (CUSTOMER_MODULE_IMPLEMENTATION.md §5.4–5.5): one transaction creating the
 * customer, bank-details row, guarantors and next of kin, then the server-derived KYC status and an audit entry.
 */
class CustomerRegistrar
{
    /**
     * Scalar payload fields (camelCase) => customer column.
     *
     * @var array<string, string>
     */
    public const COLUMNS = [
        'branchId' => 'branch_id',
        'customerCategoryId' => 'customer_category_id',
        'firstName' => 'first_name',
        'middleName' => 'middle_name',
        'lastName' => 'last_name',
        'dob' => 'date_of_birth',
        'gender' => 'gender',
        'phone' => 'phone',
        'idTypeId' => 'id_type_id',
        'idNumber' => 'id_number',
        'nidaNumber' => 'nida_number',
        'nationalIdNumber' => 'national_id_number',
        'voterIdNumber' => 'voter_id_number',
        'driverLicenceNumber' => 'driver_licence_number',
        'passportNumber' => 'passport_number',
        'workIdNumber' => 'work_id_number',
        'tinNumber' => 'tin_number',
        'alternativePhone' => 'alternative_phone',
        'email' => 'email',
        'nationality' => 'nationality',
        'maritalStatusId' => 'marital_status_id',
        'maritalStatus' => 'marital_status',
        'dependentsCount' => 'dependents',
        'residenceType' => 'residence_type',
        'regionId' => 'region_id',
        'districtId' => 'district_id',
        'wardId' => 'ward_id',
        'wardName' => 'ward_name',
        'streetId' => 'street_id',
        'streetName' => 'street_name',
        'village' => 'village',
        'houseNumber' => 'house_number',
        'postalCode' => 'postal_code',
        'landmark' => 'landmark',
        'placeOfEmployment' => 'place_of_employment',
        'checkNumber' => 'check_number',
        'basicSalary' => 'basic_salary',
        'takeHome' => 'take_home',
        'monthlyIncome' => 'monthly_income',
        'retirementDate' => 'retirement_date',
        'occupation' => 'occupation',
        'employer' => 'employer',
        'employerId' => 'employer_id',
        'department' => 'department',
        'councilNumber' => 'council_number',
        'employmentType' => 'employment_type',
        'workType' => 'work_type',
        'employmentTypeId' => 'employment_type_id',
        'workTypeId' => 'work_type_id',
        'occupationId' => 'occupation_id',
        'sectorId' => 'sector_id',
        'sectorCategoryId' => 'sector_category_id',
        'contractTypeId' => 'contract_type_id',
        'contractExpiryDate' => 'contract_expiry_date',
        'businessName' => 'business_name',
        'businessType' => 'business_type',
        'businessAddress' => 'business_address',
        'bankId' => 'bank_id',
        'bankBranch' => 'bank_branch',
        'mobileMoneyProviderId' => 'mobile_money_provider_id',
        'mobileMoneyProvider' => 'mobile_money_provider',
        'walletNumber' => 'wallet_number',
        'cardLastFour' => 'card_last_four',
        'cardExpiryMonth' => 'card_expiry_month',
        'cardExpiryYear' => 'card_expiry_year',
        'accountTypeId' => 'account_type_id',
        'customerTypeId' => 'customer_type_id',
        'loanTypeId' => 'loan_type_id',
        'registrationSource' => 'registration_source',
        'createdDevice' => 'created_device',
        'nidaVerifiedAt' => 'nida_verified_at',
        'otpVerifiedAt' => 'otp_verified_at',
    ];

    public function __construct(
        private DynamicFormValidator $forms,
        private KycStatusCalculator $kyc,
        private StepTwoFields $stepTwo,
        private RequirementProfiles $profiles,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  validated camelCase payload
     */
    public function register(array $payload, Employee $actor): Customer
    {
        return DB::transaction(function () use ($payload, $actor): Customer {
            $category = $this->category($payload);

            $customer = new Customer;
            $customer->forceFill($this->columns($payload, $category) + [
                'company_id' => $actor->company_id,
                'customer_number' => $this->nextCustomerNumber(),
                'employee_id' => $payload['employeeId'] ?? $actor->id,
                'status' => 'pending',
                'account_status' => 'active',
                'approval_status' => 'pending',
                'kyc_status' => KycStatusCalculator::INCOMPLETE,
                'created_by' => $actor->id,
            ]);
            $customer->save();

            $this->syncBankDetails($customer, $payload);
            $this->replaceNextOfKin($customer, $payload['nextOfKin'] ?? []);
            $this->replaceGuarantors($customer, $payload['guarantors'] ?? []);

            $status = $this->kyc->refresh($customer);

            $this->audit($customer, 'Customer.registered', [
                'customer_number' => $customer->customer_number,
                'kyc_status' => $status,
                'approval_status' => $customer->approval_status,
            ]);

            return $customer;
        });
    }

    /**
     * Update from a (partial) payload: only the fields sent are changed; list fields sent replace the rows.
     *
     * @param  array<string, mixed>  $payload
     */
    public function update(Customer $customer, array $payload, Employee $actor): Customer
    {
        return DB::transaction(function () use ($customer, $payload, $actor): Customer {
            $merged = $this->payloadFor($customer, $payload);
            $columns = $this->columns($merged, $this->category($merged));
            $columns['employee_id'] = $merged['employeeId'] ?? $customer->employee_id;
            if (array_key_exists('updatedDevice', $payload)) {
                $columns['updated_device'] = $payload['updatedDevice'];
            }

            $customer->forceFill($columns)->save();

            if (array_key_exists('bankDetails', $payload)) {
                $this->syncBankDetails($customer, $payload);
            }
            if (array_key_exists('nextOfKin', $payload)) {
                $this->replaceNextOfKin($customer, $payload['nextOfKin'] ?? []);
            }
            if (array_key_exists('guarantors', $payload)) {
                $this->replaceGuarantors($customer, $payload['guarantors'] ?? []);
            }

            $status = $this->kyc->refresh($customer);
            $this->audit($customer, 'Customer.details_updated', ['fields' => array_keys($payload), 'kyc_status' => $status], [], $actor);

            return $customer;
        });
    }

    /**
     * The customer's current values in the registration payload shape, overlaid with the sent values.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function payloadFor(Customer $customer, array $overrides = []): array
    {
        $current = [];
        foreach (self::COLUMNS as $field => $column) {
            $value = $customer->getAttribute($column);
            $current[$field] = $value instanceof \DateTimeInterface
                ? ($field === 'dob' || str_ends_with($field, 'Date') ? $value->format('Y-m-d') : $value->format('Y-m-d H:i:s'))
                : $value;
        }
        $current['employeeId'] = $customer->employee_id;
        $current['paymentMethod'] = $customer->payment_method;
        $current['accountName'] = $customer->account_name;
        $current['dynamicFormData'] = $customer->dynamic_form_data ?? [];
        $current['bankDetails'] = filled($customer->account_number) ? [
            'bankName' => $customer->bank_name,
            'accountNumber' => $customer->account_number,
            'accountName' => $customer->account_name,
        ] : null;
        $current['nextOfKin'] = $customer->nextOfKins()->get()->map(fn ($row): array => ['name' => $row->name])->all();
        $current['guarantors'] = $customer->guarantors()->whereNull('loan_id')->get()->map(fn ($row): array => ['name' => $row->name])->all();

        return array_replace($current, $overrides);
    }

    /**
     * Stored payment method: the sent value, else derived (wallet or provider → mno; account number or bank → bank).
     *
     * @param  array<string, mixed>  $payload
     */
    public function paymentMethod(array $payload): ?string
    {
        $sent = $payload['paymentMethod'] ?? null;
        if (in_array($sent, ['mno', 'bank'], true)) {
            return $sent;
        }

        return match (true) {
            filled($payload['walletNumber'] ?? null) || filled($payload['mobileMoneyProviderId'] ?? null) => 'mno',
            filled(Arr::get($payload, 'bankDetails.accountNumber')) || filled($payload['bankId'] ?? null) => 'bank',
            default => null,
        };
    }

    /**
     * Next `CU-000001` style number: highest existing sequence + 1.
     */
    public function nextCustomerNumber(): string
    {
        $highest = Customer::withTrashed()
            ->where('customer_number', 'like', 'CU-%')
            ->lockForUpdate()
            ->selectRaw('MAX(CAST(SUBSTRING(customer_number, 4) AS UNSIGNED)) as highest')
            ->value('highest');

        return sprintf('CU-%06d', ((int) $highest) + 1);
    }

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $before
     */
    public function audit(Customer $customer, string $action, array $after = [], array $before = [], ?Employee $actor = null): void
    {
        $actor ??= auth()->user();

        AuditLog::create([
            'company_id' => $customer->company_id,
            'employee_id' => $actor?->getKey(),
            'action' => $action,
            'auditable_type' => $customer->getMorphClass(),
            'auditable_id' => $customer->id,
            'before' => $before ?: null,
            'after' => $after ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function columns(array $payload, ?CustomerCategory $category): array
    {
        $columns = [];
        foreach (self::COLUMNS as $field => $column) {
            if (array_key_exists($field, $payload)) {
                $value = $payload[$field];
                $columns[$column] = is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
            }
        }

        // Standard Step 2 fields the customer type omits are not part of its answers and are never stored.
        if ($category !== null) {
            $accountTypeId = filled($payload['accountTypeId'] ?? null) ? (int) $payload['accountTypeId'] : null;
            $profile = $this->profiles->resolve((int) $category->company_id, $accountTypeId, (int) $category->id);
            foreach ($this->stepTwo->omittedColumns($category, $profile) as $field) {
                unset($columns[self::COLUMNS[$field]]);
            }
        }

        foreach (['first_name', 'last_name', 'phone', 'gender'] as $required) {
            if (array_key_exists($required, $columns) && $columns[$required] === null) {
                unset($columns[$required]);
            }
        }

        $bankDetails = is_array($payload['bankDetails'] ?? null) ? $payload['bankDetails'] : null;
        $columns['bank_name'] = $bankDetails['bankName'] ?? (filled($payload['bankId'] ?? null) ? Bank::query()->whereKey($payload['bankId'])->value('name') : null);
        $columns['account_number'] = $bankDetails['accountNumber'] ?? null;
        $columns['account_name'] = filled($payload['accountName'] ?? null) ? trim((string) $payload['accountName']) : ($bankDetails['accountName'] ?? null);
        $columns['payment_method'] = $this->paymentMethod($payload);

        if (blank($payload['mobileMoneyProvider'] ?? null) && filled($payload['mobileMoneyProviderId'] ?? null)) {
            $columns['mobile_money_provider'] = MobileMoneyProvider::query()->whereKey($payload['mobileMoneyProviderId'])->value('name');
        }
        if (filled($payload['maritalStatusId'] ?? null)) {
            $columns['marital_status'] = MaritalStatus::query()->whereKey($payload['maritalStatusId'])->value('name') ?? ($payload['maritalStatus'] ?? null);
        }

        // Legacy text mirrors read by reports and older screens.
        $columns['district'] = filled($payload['districtId'] ?? null) ? District::query()->whereKey($payload['districtId'])->value('name') : null;
        if (blank($columns['ward_name'] ?? null) && filled($payload['wardId'] ?? null)) {
            $columns['ward_name'] = Ward::query()->whereKey($payload['wardId'])->value('name');
        }
        $columns['ward'] = $columns['ward_name'] ?? null;
        $columns['street'] = $columns['street_name'] ?? null;

        // Answers mapped to columns through storesIn, and the JSON answers.
        foreach ($this->forms->schema($category) as $field) {
            $column = $this->forms->storesInColumn($field);
            if ($column !== null && array_key_exists($field['storesIn'], $payload)) {
                $value = $payload[$field['storesIn']];
                $columns[$column] = $this->forms->isBlank($value) ? null : $value;
            }
        }
        $columns['dynamic_form_data'] = (object) $this->forms->answers($category, $payload);

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function category(array $payload): ?CustomerCategory
    {
        return filled($payload['customerCategoryId'] ?? null) ? CustomerCategory::query()->find($payload['customerCategoryId']) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncBankDetails(Customer $customer, array $payload): void
    {
        $details = $payload['bankDetails'] ?? null;
        if (! is_array($details)) {
            $customer->bankDetail()->delete();

            return;
        }

        $customer->bankDetail()->updateOrCreate([], [
            'bank_name' => $details['bankName'],
            'account_number' => $details['accountNumber'],
            'account_name' => $details['accountName'],
            'phone' => $details['phoneNumber'] ?? null,
            'check_number' => $details['checkNumber'] ?? null,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceNextOfKin(Customer $customer, array $rows): void
    {
        $customer->nextOfKins()->delete();

        foreach ($rows as $row) {
            $customer->nextOfKins()->create([
                'name' => trim((string) $row['name']),
                'relationship' => $row['relationship'],
                'phone' => trim((string) $row['phone']),
                'address' => filled($row['address'] ?? null) ? trim((string) $row['address']) : null,
            ]);
        }
    }

    /**
     * Customer-level guarantors (rows without a loan) are replaced; loan guarantors are kept.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceGuarantors(Customer $customer, array $rows): void
    {
        $customer->guarantors()->whereNull('loan_id')->delete();

        foreach ($rows as $row) {
            $customer->guarantors()->create([
                'name' => trim((string) $row['name']),
                'phone' => trim((string) $row['phone']),
                'nida_number' => filled($row['nidaNumber'] ?? null) ? trim((string) $row['nidaNumber']) : null,
                'relationship' => $row['relationship'],
                'address' => filled($row['address'] ?? null) ? trim((string) $row['address']) : null,
                'occupation' => filled($row['occupation'] ?? null) ? trim((string) $row['occupation']) : null,
            ]);
        }
    }
}
