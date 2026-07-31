<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Domain\Organization\Enums\BranchType;
use App\Enums\ActiveStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query parameters for GET /branches — the standard CRUD list contract from
 * spec §15, plus the hierarchy and HQ filters §12 implies.
 *
 * Query keys are snake_case per spec §1.
 */
final class IndexBranchRequest extends FormRequest
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
            'status' => ['sometimes', 'nullable', 'string', Rule::in(ActiveStatus::values())],
            'type' => ['sometimes', 'nullable', 'string', Rule::in(BranchType::values())],
            'region_id' => ['sometimes', 'nullable', 'integer'],
            'zone_id' => ['sometimes', 'nullable', 'integer'],
            'parent_branch_id' => ['sometimes', 'nullable', 'integer'],
            'is_head_office' => ['sometimes', 'boolean'],
            'include_deleted' => ['sometimes', 'boolean'],

            // Branches are a lookup the branch switcher and several forms load
            // whole; paginating them by default would break those callers.
            'paginate' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
