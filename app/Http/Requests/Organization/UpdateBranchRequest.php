<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Domain\Organization\Enums\BranchType;
use App\Enums\ActiveStatus;
use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateBranchRequest extends FormRequest
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
        $branch = $this->route('branch');
        $branchId = $branch instanceof Branch ? $branch->getKey() : null;

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:150',
                Rule::unique('branches', 'name')->ignore($branchId)->whereNull('deleted_at'),
            ],
            /*
             * Optional: derived from the name when absent. Uppercase and
             * alphanumeric because it is a segment of a payment reference a
             * customer reads aloud — punctuation and case would not survive the
             * journey to a teller window intact.
             */
            'code' => ['nullable', 'string', 'min:2', 'max:12', 'regex:/^[A-Za-z0-9]+$/', Rule::unique('branches', 'code')->ignore($branchId)],
            'regionId' => ['nullable', 'integer', Rule::exists('regions', 'id')],
            'zoneId' => ['nullable', 'integer', Rule::exists('zones', 'id')->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'type' => ['required', 'string', Rule::in(BranchType::values())],

            /*
             * A branch may not be its own parent. This catches the trivial
             * case here so it reads as a field error; the deeper
             * "parent is one of my own descendants" case needs the tree and is
             * checked in UpdateBranchAction.
             */
            'parentBranchId' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->whereNull('deleted_at'),
                Rule::notIn([$branchId]),
            ],
            'status' => ['sometimes', 'string', Rule::in(ActiveStatus::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parentBranchId.not_in' => 'A branch cannot roll up into itself.',
        ];
    }
}
