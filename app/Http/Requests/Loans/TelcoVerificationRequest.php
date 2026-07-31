<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /vodacom/kyc-verify` (§15.2).
 *
 * `passed` is explicit rather than inferred: the real integration returns a
 * match result, and the credit officer records what came back. Defaulting it
 * either way would put words in the provider's mouth.
 */
final class TelcoVerificationRequest extends FormRequest
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
        return ['passed' => ['required', 'boolean']];
    }
}
