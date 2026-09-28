<?php

namespace App\Http\Requests\Api\Hrm;

use App\Models\Employee;
use App\Services\AccessControl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * HRM → All Active Staff → Privilege: the complete set of permissions the employee should effectively hold.
 * Authorization runs before validation: 403 without `hrm.staff_privileges`, 404 for staff of another company or
 * outside the actor's branch scope. Only permission keys defined in config/permissions.php are accepted.
 */
class StaffPrivilegesRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(Gate::allows('hrm.staff_privileges'), 403, 'You do not have permission to perform this action.');

        /** @var Employee $actor */
        $actor = $this->user();
        abort_unless(app(AccessControl::class)->scope(Employee::query(), $actor)->whereKey($this->employee()->id)->exists(), 404);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(array_keys(config('permissions.permissions')))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['permissions.*.in' => 'The privilege :input does not exist.'];
    }

    public function employee(): Employee
    {
        /** @var Employee */
        return $this->route('employee');
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_values(array_unique($this->input('permissions', [])));
    }
}
