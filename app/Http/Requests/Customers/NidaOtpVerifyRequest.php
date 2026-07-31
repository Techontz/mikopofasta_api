<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;

/** Mirrors the frontend's NidaOtpVerifyInputSchema (otp exactly 6 chars). */
final class NidaOtpVerifyRequest extends FormRequest
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
            'nidaNumber' => ['required', 'string', 'min:10', 'max:30'],
            'otp' => ['required', 'string', 'size:6'],
        ];
    }
}
