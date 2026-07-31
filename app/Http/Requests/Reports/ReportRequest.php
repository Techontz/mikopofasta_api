<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The shared query string every report endpoint accepts — §15.6's
 * `?branch_id=&period=&from=&to=`.
 *
 * Validated rather than trusted: a malformed `period` would silently match no
 * rows and a reader would conclude there was no activity, which is a worse
 * failure than a 422.
 */
final class ReportRequest extends FormRequest
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
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'period' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period.regex' => 'Period must be in YYYY-MM format.',
            'from.date_format' => 'From must be a date in YYYY-MM-DD format.',
            'to.date_format' => 'To must be a date in YYYY-MM-DD format.',
            'to.after_or_equal' => 'To must not be earlier than from.',
        ];
    }
}
