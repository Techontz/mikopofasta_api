<?php

declare(strict_types=1);

namespace App\Http\Requests\Expenses;

use App\Domain\Expenses\Enums\ExpenseRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The approve/reject decision.
 *
 * `decision` is limited to the two terminal states — "decide this pending
 * request into pending again" is not a decision, and allowing it would let a
 * caller clear a comment through an endpoint that reads like an approval.
 */
final class DecideExpenseRequestRequest extends FormRequest
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
                    ExpenseRequestStatus::Approved->value,
                    ExpenseRequestStatus::Rejected->value,
                ]),
            ],
            'comment' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decision.in' => 'A request can only be approved or rejected.',
            'comment.max' => 'Keep the comment under 300 characters.',
        ];
    }
}
