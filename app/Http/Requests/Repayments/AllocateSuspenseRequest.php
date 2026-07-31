<?php

declare(strict_types=1);

namespace App\Http\Requests\Repayments;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `POST /payments/allocate` — Finance placing a suspense item (§15.3). */
final class AllocateSuspenseRequest extends FormRequest
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
            'loanId' => ['required', 'integer', Rule::exists('loans', 'id')->whereNull('deleted_at')],
        ];
    }
}
