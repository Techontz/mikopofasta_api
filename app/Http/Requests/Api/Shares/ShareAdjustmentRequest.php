<?php

namespace App\Http\Requests\Api\Shares;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Correct a holding by adding or removing shares, with a reason (no ledger entry).
 */
class ShareAdjustmentRequest extends SharesRequest
{
    protected function permission(): string
    {
        return 'shares.manage';
    }

    protected function numericFields(): array
    {
        return ['shares'];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'share_holder_id' => ['required', 'integer', $this->shareHolderRule()],
            'direction' => ['required', 'in:increase,decrease'],
            'shares' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:90'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['share_holder_id' => 'shareholder'];
    }
}
