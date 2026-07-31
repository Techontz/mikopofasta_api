<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Domain\Organization\Enums\BranchType;
use App\Enums\ActiveStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's BranchInputSchema
 * (features/admin/organization/branches-actions.ts).
 *
 * camelCase keys, matching what the frontend form submits — see the casing
 * note on App\Support\ApiResponse.
 */
final class StoreBranchRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:150', Rule::unique('branches', 'name')->whereNull('deleted_at')],
            'regionId' => ['nullable', 'integer', Rule::exists('regions', 'id')],
            'zoneId' => ['nullable', 'integer', Rule::exists('zones', 'id')->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'type' => ['required', 'string', Rule::in(BranchType::values())],
            'parentBranchId' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'status' => ['sometimes', 'string', Rule::in(ActiveStatus::values())],
        ];
    }
}
