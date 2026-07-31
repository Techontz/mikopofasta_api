<?php

declare(strict_types=1);

namespace App\Http\Requests\Expenses;

use Illuminate\Foundation\Http\FormRequest;

/** The comment dialog. Nullable, because clearing a comment is a valid edit. */
final class CommentOnExpenseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'comment' => ['present', 'nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'comment.max' => 'Keep the comment under 300 characters.',
        ];
    }
}
