<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Domain\Reports\DTOs\ReportQuery;
use App\Domain\Reports\Services\ReportExporter;
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

            /*
             * Presentation, not filtering. These decide which of the computed
             * rows are shown and in what order; the four above decide what the
             * figures are. Only the four are echoed in `filters_applied`, so a
             * reader is never told that sorting changed the totals.
             */
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:60'],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.ReportQuery::MAX_PER_PAGE],

            'format' => ['sometimes', 'nullable', Rule::in(ReportExporter::FORMATS)],
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
            'format.in' => 'Export format must be csv, xlsx or pdf.',
        ];
    }
}
