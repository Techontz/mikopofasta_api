<?php

namespace App\Http\Requests\Api\Shares;

use App\Models\ShareTransaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Issue new shares: shareholder / investor, shares, issue price per share (defaults to the current share value),
 * issue date, and either payment now (CASH → COMPANY ACCOUNT, BANK → a company bank account), a link to a recorded
 * capital contribution, or an explicit non-cash bonus issuance.
 */
class ShareIssuanceRequest extends SharesRequest
{
    protected function permission(): string
    {
        return 'shares.issue';
    }

    protected function numericFields(): array
    {
        return ['shares', 'price_per_share'];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'share_holder_id' => ['required', 'integer', $this->shareHolderRule()],
            'type' => ['required', Rule::in(['issuance', 'bonus_issuance'])],
            'payment_treatment' => ['nullable', 'required_if:type,issuance', Rule::in([ShareTransaction::TREATMENT_PAID, ShareTransaction::TREATMENT_LINKED])],
            'shares' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'price_per_share' => ['nullable', 'numeric', 'gt:0', 'max:999999999999'],
            'issue_date' => ['nullable', 'date', 'before_or_equal:today'],
            'pay_method' => ['nullable', 'required_if:payment_treatment,'.ShareTransaction::TREATMENT_PAID, 'in:CASH,BANK'],
            'bank_account_id' => ['nullable', 'required_if:pay_method,BANK', 'integer', Rule::exists('bank_accounts', 'id')->where('company_id', $companyId)],
            'capital_id' => ['nullable', 'required_if:payment_treatment,'.ShareTransaction::TREATMENT_LINKED, 'integer', Rule::exists('capitals', 'id')->where('company_id', $companyId)],
            'receipt_number' => ['nullable', 'string', 'max:50'],
            'cheque_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:90'],
            'document' => ['nullable', 'file', 'mimes:'.implode(',', self::DOCUMENT_MIMES), 'max:'.self::DOCUMENT_MAX_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'share_holder_id' => 'shareholder',
            'payment_treatment' => 'payment treatment',
            'price_per_share' => 'issue price per share',
            'issue_date' => 'issue date',
            'pay_method' => 'payment method',
            'bank_account_id' => 'receiving bank account',
            'capital_id' => 'capital contribution',
            'document' => 'supporting document',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_treatment.required_if' => 'Select how the shares are paid for.',
            'pay_method.required_if' => 'Select the payment method.',
            'capital_id.required_if' => 'Select the recorded capital contribution to link.',
        ];
    }
}
