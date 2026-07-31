<?php

declare(strict_types=1);

namespace App\Http\Requests\Expenses;

use App\Domain\Expenses\Enums\ExpenseScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Mirrors the frontend's `ExpenseNameInputSchema`, plus the register. */
final class StoreExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // min:2 matches the frontend's "Enter an expense name."
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'scope' => ['required', Rule::enum(ExpenseScope::class)],
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
