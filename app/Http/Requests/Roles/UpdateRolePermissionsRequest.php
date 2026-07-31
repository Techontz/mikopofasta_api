<?php

declare(strict_types=1);

namespace App\Http\Requests\Roles;

use App\Domain\Auth\Enums\PermissionName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The complete permission set a role should hold after this call.
 *
 * `present` rather than `required` so that clearing every permission from a
 * role is expressible — `required` rejects an empty array, which would make
 * "revoke the last permission" impossible.
 */
final class UpdateRolePermissionsRequest extends FormRequest
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
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionName::values())],
        ];
    }
}
