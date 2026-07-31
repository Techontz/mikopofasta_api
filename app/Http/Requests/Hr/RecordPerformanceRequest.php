<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use App\Domain\Hr\Enums\PerformanceRating;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /staff/performance` — §15.5.
 *
 * Targets and achievements are free-form maps of metric → number, matching
 * §2.9's JSON columns and the frontend's `z.record(z.string(), z.number())`.
 * Constraining the keys would freeze the metrics a manager may review.
 */
final class RecordPerformanceRequest extends FormRequest
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
            'staffProfileId' => [
                'required', 'integer',
                Rule::exists('staff_profiles', 'id')->whereNull('deleted_at'),
            ],
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['required', 'numeric'],
            'achieved' => ['required', 'array', 'min:1'],
            'achieved.*' => ['required', 'numeric'],
            'rating' => ['sometimes', 'nullable', 'string', Rule::in(PerformanceRating::values())],
        ];
    }
}
