<?php

namespace App\Http\Requests\Api\Customers;

use App\Models\Customer;
use App\Models\Employee;
use App\Services\AccessControl;
use App\Services\Customers\CustomerRegistrar;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

/**
 * PUT /customers/{customer} — the registration payload, partially: only the fields sent are validated and changed.
 * Requirement-profile, payment and customer-type rules evaluate the customer as it would be after the update.
 *
 * Staff who register customers (customers.manage) and staff who may only correct details (customers.edit) both
 * update here; moving a customer to another branch or officer stays with customers.manage.
 */
class UpdateCustomerRequest extends StoreCustomerRequest
{
    public function authorize(): bool
    {
        abort_unless(Gate::any(['customers.manage', 'customers.edit']), 403, 'You do not have permission to perform this action.');

        /** @var Employee $actor */
        $actor = $this->user();
        abort_unless(app(AccessControl::class)->scope(Customer::query(), $actor)->whereKey($this->customer()->id)->exists(), 404);

        $this->assertBranchInScope($this->input('branchId'));

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = collect($this->staticRules())
            ->map(fn (array $rules): array => ['sometimes', ...array_values(array_filter($rules, fn ($rule): bool => $rule !== 'present'))])
            ->all();

        if (! Gate::allows('customers.manage')) {
            $rules['branchId'][] = 'prohibited';
            $rules['employeeId'][] = 'prohibited';
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'branchId.prohibited' => 'Only staff who register customers can move a customer to another branch.',
            'employeeId.prohibited' => 'Only staff who register customers can change the assigned officer.',
        ];
    }

    public function customer(): Customer
    {
        /** @var Customer */
        return $this->route('customer');
    }

    protected function ignoredCustomerId(): ?int
    {
        return $this->customer()->id;
    }

    protected function currentCustomerCategoryId(): ?int
    {
        return $this->customer()->customer_category_id !== null ? (int) $this->customer()->customer_category_id : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function effectivePayload(): array
    {
        return app(CustomerRegistrar::class)->payloadFor($this->customer(), $this->all());
    }

    /**
     * On an update, only errors about what the request changes are reported, so existing records with older data
     * can still be edited. A changed customer type re-checks all of its answers.
     */
    protected function reportsBusinessError(string $key): bool
    {
        $root = explode('.', $key)[0];
        $related = [
            'regionId' => ['regionId', 'districtId'], 'districtId' => ['regionId', 'districtId'],
            'idTypeId' => ['idTypeId', 'idNumber', 'nidaNumber', 'nationalIdNumber', 'voterIdNumber', 'driverLicenceNumber', 'passportNumber', 'workIdNumber'],
            'employer' => ['employer', 'employerId', 'placeOfEmployment'], 'workType' => ['workType', 'workTypeId', 'employmentType', 'employmentTypeId'],
            'takeHome' => ['takeHome', 'basicSalary', 'monthlyIncome'], 'bankDetails' => ['bankDetails', 'walletNumber', 'paymentMethod'],
            'mobileMoneyProviderId' => ['mobileMoneyProviderId', 'paymentMethod'], 'walletNumber' => ['walletNumber', 'paymentMethod'], 'bankId' => ['bankId', 'paymentMethod'],
        ];

        if ($this->has('customerCategoryId')) {
            return true;
        }

        return collect($related[$root] ?? [$root])->contains(fn (string $field): bool => $this->has($field));
    }

    /**
     * @return list<int>
     */
    protected function allowedOfficerIds(Employee $actor): array
    {
        return array_values(array_filter([(int) $actor->id, $this->customer()->employee_id !== null ? (int) $this->customer()->employee_id : null]));
    }
}
