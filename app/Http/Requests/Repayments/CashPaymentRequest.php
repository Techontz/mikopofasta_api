<?php

declare(strict_types=1);

namespace App\Http\Requests\Repayments;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `POST /payments/cash` — the teller's entry (§15.3). */
final class CashPaymentRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
        ];
    }
}
