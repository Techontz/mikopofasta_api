<?php

namespace App\Http\Requests\Api\Loans;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Manual decision on an escalated disbursement (Documents: cancel loan / move to suspense / try different channel).
 * A different channel may also name the disbursement source account.
 */
class EscalationRequest extends DisbursementSourceRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['cancel', 'suspense', 'other_channel'])],
            'channel' => ['required_if:action,other_channel', 'nullable', Rule::in(['airtel', 'bank', 'cash'])],
            'reason' => ['required', 'string', 'max:1000'],
        ] + parent::rules();
    }
}
