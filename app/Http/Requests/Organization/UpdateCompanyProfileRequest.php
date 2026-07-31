<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's UpdateCompanyProfileInputSchema
 * (types/organization.ts).
 */
final class UpdateCompanyProfileRequest extends FormRequest
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
            'legalName' => ['required', 'string', 'min:2', 'max:150'],
            'tradingName' => ['required', 'string', 'min:2', 'max:150'],
            'registrationNumber' => ['required', 'string', 'max:60'],
            'tinNumber' => ['required', 'string', 'max:60'],
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'email' => ['required', 'email', 'max:150'],
            'address' => ['required', 'string', 'max:255'],
            'headquartersBranchId' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
        ];
    }
}
