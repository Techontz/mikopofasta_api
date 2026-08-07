<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/profile/photo — a staff portrait.
 *
 * Images only and capped, on the same reasoning as every other upload here:
 * this lands on a private disk, and an unbounded upload is both a storage and
 * a denial-of-service concern.
 */
final class UpdateProfilePhotoRequest extends FormRequest
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
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.image' => 'Your profile picture must be an image.',
            'photo.max' => 'Your profile picture must not be larger than 4 MB.',
        ];
    }
}
