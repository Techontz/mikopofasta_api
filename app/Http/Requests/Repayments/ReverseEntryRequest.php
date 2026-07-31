<?php

declare(strict_types=1);

namespace App\Http\Requests\Repayments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /ledger/{entry}/reverse` — mirrors the frontend's
 * ReverseEntryInputSchema, whose reason has a 3-character minimum.
 */
final class ReverseEntryRequest extends FormRequest
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
        return ['reason' => ['required', 'string', 'min:3', 'max:255']];
    }
}
