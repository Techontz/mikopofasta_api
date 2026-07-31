<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Domain\Customers\Enums\MaritalStatus;
use App\Domain\Customers\Enums\ResidenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /customers/{customer}/additional-data — spec §15.1. */
final class AdditionalDataRequest extends FormRequest
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
            'maritalStatus' => ['sometimes', 'nullable', 'string', Rule::in(MaritalStatus::values())],
            'regionId' => ['sometimes', 'nullable', 'integer', Rule::exists('regions', 'id')],
            'districtId' => ['sometimes', 'nullable', 'integer', Rule::exists('districts', 'id')],
            'wardId' => ['sometimes', 'nullable', 'integer', Rule::exists('wards', 'id')],
            'streetId' => ['sometimes', 'nullable', 'integer', Rule::exists('streets', 'id')],
            'residenceType' => ['sometimes', 'nullable', 'string', Rule::in(ResidenceType::values())],

            'bankDetails' => ['sometimes', 'nullable', 'array'],
            'bankDetails.bankName' => ['required_with:bankDetails', 'string', 'max:100'],
            'bankDetails.accountNumber' => ['required_with:bankDetails', 'string', 'max:50'],
            'bankDetails.accountName' => ['required_with:bankDetails', 'string', 'max:150'],
            'bankDetails.phoneNumber' => ['nullable', 'string', 'max:20'],
            'bankDetails.checkNumber' => ['nullable', 'string', 'max:50'],
        ];
    }
}
