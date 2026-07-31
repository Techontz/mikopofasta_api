<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mirrors the frontend's ReserveSettingInputSchema. A reserve is a share of the
 * portfolio, so it is bounded at both ends.
 */
final class UpdateReserveSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
