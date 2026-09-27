<?php

namespace App\Http\Requests\Api\Shares;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Transfer existing shares between two different shareholders. The consideration is information only (no ledger).
 */
class ShareTransferRequest extends SharesRequest
{
    protected function permission(): string
    {
        return 'shares.transfer';
    }

    protected function numericFields(): array
    {
        return ['shares', 'consideration_per_share'];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from_share_holder_id' => ['required', 'integer', $this->shareHolderRule()],
            'to_share_holder_id' => ['required', 'integer', 'different:from_share_holder_id', $this->shareHolderRule()],
            'shares' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'consideration_per_share' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'transfer_date' => ['nullable', 'date', 'before_or_equal:today'],
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
            'from_share_holder_id' => 'transferring shareholder',
            'to_share_holder_id' => 'receiving shareholder',
            'consideration_per_share' => 'consideration per share',
            'transfer_date' => 'transfer date',
            'document' => 'supporting document',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['to_share_holder_id.different' => 'The receiving shareholder must be different from the transferring shareholder.'];
    }
}
