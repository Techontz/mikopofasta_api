<?php

namespace App\Http\Requests\Api\Shares;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Record a new share value (memorandum only — no ledger entry).
 */
class ShareValuationRequest extends SharesRequest
{
    protected function permission(): string
    {
        return 'shares.value';
    }

    protected function numericFields(): array
    {
        return ['new_value'];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'new_value' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'valuation_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:90'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['new_value' => 'new share value', 'valuation_date' => 'valuation date'];
    }
}
