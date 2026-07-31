<?php

declare(strict_types=1);

namespace App\Http\Requests\Treasury;

use App\Domain\Treasury\Enums\HqTransactionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The approve/reject decision. Limited to the two terminal states — deciding
 * something back into pending is not a decision.
 */
final class DecideHqTransactionRequest extends FormRequest
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
                    HqTransactionStatus::Approved->value,
                    HqTransactionStatus::Rejected->value,
                ]),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decision.in' => 'A transaction can only be approved or rejected.',
        ];
    }
}
