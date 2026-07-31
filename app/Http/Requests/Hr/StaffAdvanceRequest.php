<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `POST /staff/advance/request` — mirrors StaffAdvanceRequestInputSchema. */
final class StaffAdvanceRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
        ];
    }
}
