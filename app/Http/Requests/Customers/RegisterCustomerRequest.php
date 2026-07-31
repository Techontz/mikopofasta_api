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
            'nidaNumber' => ['required', 'string', 'min:10', 'max:30', Rule::unique('customers', 'nida_number')],

            'nidaVerifiedAt' => ['required', 'date'],
            'otpVerifiedAt' => ['required', 'date'],
            'faceVerifiedAt' => ['required', 'date'],

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

            'branchId' => ['required', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'customerCategoryId' => ['required', 'integer', Rule::exists('customer_categories', 'id')->whereNull('deleted_at')],

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
