<?php

declare(strict_types=1);

namespace App\Http\Requests\Treasury;

use App\Domain\Treasury\Enums\BankTransactionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Approve or reject — the two terminal states only. */
final class DecideBankTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                Rule::in([
                    BankTransactionStatus::Approved->value,
                    BankTransactionStatus::Rejected->value,
                ]),
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['decision.in' => 'A transaction can only be approved or rejected.'];
    }
}
