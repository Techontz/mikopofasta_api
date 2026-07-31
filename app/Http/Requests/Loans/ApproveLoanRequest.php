<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's LoanApprovalInputSchema:
 * `{ decision: "approve" | "reject", reason?: string }`.
 *
 * The schema also lists "modify", but no frontend screen sends it and §15.2
 * gives it no defined behaviour, so it is not accepted — silently treating it
 * as an approval or a rejection would be inventing a rule.
 */
final class ApproveLoanRequest extends FormRequest
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
            'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required_if' => 'A rejection reason is required.'];
    }
}
