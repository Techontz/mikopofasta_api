<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /staff/advance/{approve|reject|disburse}` — §15.5 addresses an advance
 * by id in the body rather than in the path, and that shape is kept.
 */
final class DecideStaffAdvanceRequest extends FormRequest
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
            'advanceId' => ['required', 'integer', Rule::exists('staff_advances', 'id')],
        ];
    }
}
