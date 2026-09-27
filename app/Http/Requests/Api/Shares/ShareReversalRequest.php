<?php

namespace App\Http\Requests\Api\Shares;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Reverse a share transaction or the latest share valuation, with a required reason.
 */
class ShareReversalRequest extends SharesRequest
{
    protected function permission(): string
    {
        return 'shares.manage';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
