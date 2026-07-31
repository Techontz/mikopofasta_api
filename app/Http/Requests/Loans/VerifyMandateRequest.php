<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

/** `POST /bank/e-mandate/verify-otp` (§15.2). */
final class VerifyMandateRequest extends FormRequest
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
        return ['otp' => ['required', 'string', 'size:6']];
    }
}
