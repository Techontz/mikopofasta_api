<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Domain\Auth\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's setUserStatus(id, "active" | "suspended").
 */
final class UpdateUserStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(UserStatus::values())],
        ];
    }
}
