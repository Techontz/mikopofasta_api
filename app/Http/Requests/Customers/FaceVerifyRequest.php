<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /customers/{customer}/face-verify — the liveness capture.
 *
 * Images only, and capped: this is biometric data on a private disk, and an
 * unbounded upload is both a storage and a denial-of-service concern.
 */
final class FaceVerifyRequest extends FormRequest
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
            'capture' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'capture.required' => 'A liveness capture is required.',
            'capture.image' => 'The liveness capture must be an image.',
            'capture.max' => 'The liveness capture must not be larger than 5 MB.',
        ];
    }
}
