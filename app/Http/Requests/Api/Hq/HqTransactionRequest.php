<?php

namespace App\Http\Requests\Api\Hq;

use App\Enums\HqFund;
use App\Services\ShareholderAccounts;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Headquarters Transaction → Requested Transaction: Finance sends money from one of his own HQ Account List rows
 * ({@see HqFund::sources()}) to a shareholders' (Investment) account. RESERVE and DIVIDEND may go to one account only
 * ({@see HqFund::onlyDestination()}) — the reserve leaves HQ only towards the Investment RESERVE A/C (rule 3), and a
 * dividend row is the declared dividend itself, which sending to DIVIDEND A/C settles.
 */
class HqTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('hq.manage');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $funds = array_map(fn (HqFund $fund): string => $fund->value, HqFund::sources());
        $shareholderAccounts = app(ShareholderAccounts::class)->values((int) $this->user()->company_id);

        return [
            'from_account' => ['required', Rule::in($funds)],
            'to_account' => ['required', Rule::in($shareholderAccounts)],
            'amount' => ['required', 'numeric', 'min:1'],
            'charge' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $fund = HqFund::tryFrom($this->string('from_account')->toString());
            $only = $fund?->onlyDestination();

            if ($only !== null && $this->string('to_account')->toString() !== $only->value) {
                $validator->errors()->add('to_account', $fund->label().' can only be sent to '.(ShareholderAccounts::nameOf($only->value) ?? $only->label()).'.');
            }
        }];
    }
}
