<?php

namespace App\Http\Requests\Api\Expenses;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Expense requisitions from the three live forms; field names follow each live form.
 * HQ requests may carry an optional branch tag (live "Select Branch") so reports can show HQ-paid, branch-tagged expenses.
 */
class ExpenseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = ['hq' => 'hq.manage', 'bank' => 'bank.manage'][$this->input('scope')] ?? 'expenses.request';

        return $this->user()->can($permission);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;
        $expenseType = fn (string $scope) => Rule::exists('expense_types', 'id')->where('company_id', $companyId)->where('scope', $scope);
        $branch = Rule::exists('branches', 'id')->where('company_id', $companyId);

        return match ($this->input('scope')) {
            'bank' => [
                'scope' => ['required'],
                'ac_id' => ['required', Rule::exists('bank_accounts', 'id')->where('company_id', $companyId)],
                'exp_id' => ['required', $expenseType('bank')],
                'amount' => ['required', 'numeric', 'min:1'],
                'comment' => ['required', 'string', 'max:1000'],
            ],
            'hq' => [
                'scope' => ['required'],
                'blanch_id' => ['nullable', $branch],
                'ex_id' => ['required', $expenseType('hq')],
                'req_amount' => ['required', 'numeric', 'min:1'],
                'req_description' => ['required', 'string', 'max:1000'],
            ],
            default => [
                'scope' => ['required', Rule::in(['branch', 'hq', 'bank'])],
                // A branch expense belongs to a real branch; Head Office spends through Headquarters Expenses.
                'blanch_id' => ['required', (clone $branch)->where('is_head_office', false)],
                'ex_id' => ['required', $expenseType('branch')],
                'req_amount' => ['required', 'numeric', 'min:1'],
                'req_description' => ['required', 'string', 'max:1000'],
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['blanch_id' => 'branch', 'ex_id' => 'expenses', 'exp_id' => 'expenses', 'ac_id' => 'account', 'req_amount' => 'amount', 'req_description' => 'description'];
    }

    /**
     * @return array{scope: string, branch_id: int|null, bank_account_id: int|null, expense_type_id: int, amount: float, description: string|null, comment: string|null}
     */
    public function requestData(): array
    {
        $scope = $this->string('scope')->toString();

        if ($scope === 'bank') {
            return [
                'scope' => $scope,
                'branch_id' => null,
                'bank_account_id' => $this->integer('ac_id'),
                'expense_type_id' => $this->integer('exp_id'),
                'amount' => $this->float('amount'),
                'description' => null,
                'comment' => $this->string('comment')->toString(),
            ];
        }

        return [
            'scope' => $scope,
            'branch_id' => $this->filled('blanch_id') ? $this->integer('blanch_id') : null,
            'bank_account_id' => null,
            'expense_type_id' => $this->integer('ex_id'),
            'amount' => $this->float('req_amount'),
            'description' => $this->string('req_description')->toString(),
            'comment' => null,
        ];
    }
}
