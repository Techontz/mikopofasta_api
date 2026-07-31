<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Enums\ActiveStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or rename a group.
 *
 * The name is unique within a branch, not globally: two branches may each run a
 * group called "Wazuri" and neither is wrong. The database enforces the same
 * pair, so a race cannot slip a duplicate past this.
 */
final class StoreGroupRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $groupId = $this->route('group')?->id;

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:120',
                Rule::unique('groups', 'name')
                    ->where('branch_id', $this->input('branchId'))
                    ->whereNull('deleted_at')
                    ->ignore($groupId),
            ],
            'branchId' => ['required', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'status' => ['sometimes', Rule::enum(ActiveStatus::class)],

            // ISO-8601 weekday: 1 = Monday … 7 = Sunday.
            'meetingDay' => ['nullable', 'integer', 'between:1,7'],
            'meetingTime' => ['nullable', 'date_format:H:i'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'This branch already has a group with that name.',
            'meetingDay.between' => 'Choose a day of the week.',
            'meetingTime.date_format' => 'Use a 24-hour time, for example 14:30.',
        ];
    }
}
