<?php

namespace App\Http\Requests\Api\Expenses;

use App\Http\Controllers\Api\V1\Expenses\ExpenseTypeController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Expense names (categories) of the three live registers; the name field follows each live form.
 */
class ExpenseTypeRequest extends FormRequest
{
    /**
     * Live input name of the expense name field per scope.
     *
     * @var array<string, string>
     */
    public const NAME_FIELDS = [
        'branch' => 'ex_name',
        'hq' => 'exp_desc',
        'bank' => 'expenses_name',
    ];

    public function authorize(): bool
    {
        $scope = $this->route('expenseType')?->scope ?? $this->input('scope');

        return collect(ExpenseTypeController::MANAGE_PERMISSIONS[$scope] ?? ['expenses.request', 'settings.manage'])->contains(fn (string $permission): bool => $this->user()->can($permission));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scope' => [$this->isMethod('post') ? 'required' : 'nullable', Rule::in(array_keys(self::NAME_FIELDS))],
            $this->nameField() => ['required', 'string', 'max:255'],
        ];
    }

    public function expenseName(): string
    {
        return $this->string($this->nameField())->toString();
    }

    private function nameField(): string
    {
        $scope = $this->route('expenseType')?->scope ?? $this->input('scope');

        return self::NAME_FIELDS[$scope] ?? 'ex_name';
    }
}
