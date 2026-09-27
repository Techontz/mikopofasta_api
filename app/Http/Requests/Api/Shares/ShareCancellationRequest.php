<?php

namespace App\Http\Requests\Api\Shares;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Cancel shares held by a shareholder (total issued shares decrease).
 */
class ShareCancellationRequest extends SharesRequest
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
            'shares' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'transaction_date' => ['nullable', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:90'],
            'document' => ['nullable', 'file', 'mimes:'.implode(',', self::DOCUMENT_MIMES), 'max:'.self::DOCUMENT_MAX_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['share_holder_id' => 'shareholder', 'transaction_date' => 'cancellation date', 'document' => 'supporting document'];
    }
}
