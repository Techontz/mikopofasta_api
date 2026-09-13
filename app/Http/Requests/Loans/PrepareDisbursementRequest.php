<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Domain\Loans\Enums\DisbursementChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Mirrors the frontend's PrepareDisbursementInputSchema. */
final class PrepareDisbursementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', Rule::in(DisbursementChannel::values())],

            /*
             * Which company account the payout leaves. `account` (the default)
             * pays from a registered Bank / Mobile Money account — the one named,
             * or the first that may send money; `cash` pays from the loan
             * branch's teller till.
             */
            'fundingSource' => ['nullable', 'string', Rule::in(['account', 'cash'])],
            'fundingBankAccountId' => [
                'nullable', 'integer',
                Rule::prohibitedIf(fn (): bool => $this->input('fundingSource') === 'cash'),
                Rule::exists('bank_accounts', 'id')->whereNull('deleted_at'),
            ],
        ];
    }
}
