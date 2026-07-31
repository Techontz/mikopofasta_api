<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use App\Domain\Hr\Enums\StaffAdvanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The filter set the five Salary Advance list screens share.
 *
 * `status` accepts the frontend's vocabulary — `active` and `repaid` — because
 * that is what the screens are written in; StaffAdvanceStatus::fromFrontend
 * translates. Both spellings are accepted so an API caller using §11's words
 * is not turned away either.
 */
final class IndexSalaryAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'status' => [
                'nullable',
                Rule::in([...StaffAdvanceStatus::values(), 'active', 'repaid']),
            ],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'staff_profile_id' => ['nullable', 'integer', Rule::exists('staff_profiles', 'id')],
            'category_id' => [
                'nullable', 'integer',
                Rule::exists('salary_advance_categories', 'id')->whereNull('deleted_at'),
            ],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['to.after_or_equal' => 'The end of the range cannot fall before its start.'];
    }
}
