<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mirrors the frontend's CloseLoanInputSchema, whose `freezeDays` defaults
 * to 30 — the post-closure cooldown from §6.
 */
final class CloseLoanRequest extends FormRequest
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
        return ['freezeDays' => ['sometimes', 'integer', 'min:0', 'max:365']];
    }
}
