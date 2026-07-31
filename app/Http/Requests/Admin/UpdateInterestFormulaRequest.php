<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Name and description only.
 *
 * `code` is deliberately absent: it is a branch in the interest engine, not a
 * label. See UpdateInterestFormulaAction for why a fourth formula is a code
 * change rather than a configuration one.
 */
final class UpdateInterestFormulaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.min' => 'Enter a formula name.'];
    }
}
