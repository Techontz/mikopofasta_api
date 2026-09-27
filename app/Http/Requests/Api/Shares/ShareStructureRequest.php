<?php

namespace App\Http\Requests\Api\Shares;

use App\Models\ShareTransaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Set up the share structure (capital basis, initial shares, optional authorised limit) and the initial allocation to
 * founders. Each allocation line states explicitly how its shares were paid for: linked to a recorded capital
 * contribution (no new journal), paid now (Dr Cash/Bank / Cr Share Capital) or allocated without cash (no journal).
 */
class ShareStructureRequest extends SharesRequest
{
    protected function permission(): string
    {
        return 'shares.manage';
    }

    protected function numericFields(): array
    {
        return ['capital_basis', 'total_shares', 'authorised_shares'];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'capital_basis' => ['required', 'numeric', 'min:1', 'max:999999999999999'],
            'total_shares' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'authorised_shares' => ['nullable', 'integer', 'min:1', 'max:1000000000000', 'gte:total_shares'],
            'established_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:90'],
            'allocations' => ['required', 'array', 'min:1', 'max:100'],
            'allocations.*.share_holder_id' => ['required', 'integer', $this->shareHolderRule()],
            'allocations.*.shares' => ['required', 'integer', 'min:1'],
            'allocations.*.treatment' => ['required', Rule::in([ShareTransaction::TREATMENT_LINKED, ShareTransaction::TREATMENT_PAID, ShareTransaction::TREATMENT_NO_CASH])],
            'allocations.*.capital_id' => ['nullable', 'required_if:allocations.*.treatment,'.ShareTransaction::TREATMENT_LINKED, 'integer', Rule::exists('capitals', 'id')->where('company_id', $this->user()->company_id)],
            'allocations.*.pay_method' => ['nullable', 'required_if:allocations.*.treatment,'.ShareTransaction::TREATMENT_PAID, 'in:CASH,BANK'],
            'allocations.*.bank_account_id' => ['nullable', 'required_if:allocations.*.pay_method,BANK', 'integer', Rule::exists('bank_accounts', 'id')->where('company_id', $this->user()->company_id)],
            'allocations.*.receipt_number' => ['nullable', 'string', 'max:50'],
            'allocations.*.cheque_number' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'capital_basis' => 'capital basis',
            'total_shares' => 'number of shares',
            'authorised_shares' => 'authorised share limit',
            'established_on' => 'structure date',
            'allocations.*.share_holder_id' => 'shareholder',
            'allocations.*.shares' => 'shares',
            'allocations.*.treatment' => 'payment treatment',
            'allocations.*.capital_id' => 'capital contribution',
            'allocations.*.pay_method' => 'payment method',
            'allocations.*.bank_account_id' => 'receiving bank account',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'authorised_shares.gte' => 'The authorised share limit cannot be lower than the initial number of shares.',
            'allocations.*.capital_id.required_if' => 'Select the recorded capital contribution to link.',
            'allocations.*.pay_method.required_if' => 'Select the payment method.',
        ];
    }
}
