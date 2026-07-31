<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use App\Domain\Hr\Enums\EmploymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Query parameters for GET /staff. */
final class IndexStaffRequest extends FormRequest
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
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'employment_status' => ['sometimes', 'nullable', 'array'],
            'employment_status.*' => ['string', Rule::in(EmploymentStatus::values())],
            'branch_id' => ['sometimes', 'nullable', 'integer'],
            'commission_eligible' => ['sometimes', 'nullable', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $value = $this->query('employment_status');

        if (is_string($value) && $value !== '') {
            $this->merge(['employment_status' => explode(',', $value)]);
        }
    }
}
