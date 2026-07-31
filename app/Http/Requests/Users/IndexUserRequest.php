<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Auth\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query parameters for GET /users, following the standard CRUD list contract
 * in spec §15: `?search=&status=&per_page=`.
 *
 * Query keys are snake_case, matching spec §1 (`?page=&per_page=`) and the
 * frontend's own URL building in features/reports/report-filters.tsx.
 */
final class IndexUserRequest extends FormRequest
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
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(UserStatus::values())],
            'role' => ['sometimes', 'nullable', 'string', Rule::in(RoleName::values())],
            'branch_id' => ['sometimes', 'nullable', 'integer'],
            'include_deleted' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
