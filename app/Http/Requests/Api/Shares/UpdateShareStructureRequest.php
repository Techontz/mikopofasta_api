<?php

namespace App\Http\Requests\Api\Shares;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Change the authorised share limit (null removes the limit). It can never fall below the shares already issued.
 */
class UpdateShareStructureRequest extends SharesRequest
{
    protected function permission(): string
    {
        return 'shares.manage';
    }

    protected function numericFields(): array
    {
        return ['authorised_shares'];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'authorised_shares' => ['present', 'nullable', 'integer', 'min:1', 'max:1000000000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['authorised_shares' => 'authorised share limit'];
    }
}
