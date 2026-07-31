<?php

declare(strict_types=1);

namespace App\Http\Requests\Expenses;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The rename form. No `scope`: which register a name belongs to is fixed at
 * creation, because moving it would silently re-file every request under it.
 */
final class UpdateExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.min' => 'Enter an expense name.',
            'name.required' => 'Enter an expense name.',
        ];
    }
}
