<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `BankAccountRecordSchema` in the frontend's types/bank.ts.
 *
 * `balance`, `todayDeposit` and `todayWithdrawal` are all derived from the
 * ledger rather than stored — the Account Balance screen shows what the account
 * holds and what moved on it today, and both have to come from the journal or
 * they can drift from it.
 *
 * The two "today" figures are only present when the caller asked for them: they
 * cost a grouped query over the day's journal lines, and the Register Account
 * screen does not show them. The controller computes them in one query for the
 * whole page and sets them on each model as `today_deposit` and
 * `today_withdrawal`; absent, they read zero.
 *
 * Deliberately NOT a static on this class. `collection()` builds each instance
 * itself and gives no way to pass anything in, so a static looks like the
 * obvious answer — but it survives the response under a persistent worker, and
 * one request's figures appearing on the next request's accounts is exactly the
 * kind of bug nobody finds until a customer reports it.
 *
 * @mixin BankAccount
 */
final class BankAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'bankName' => $this->bank_name,
            'accountName' => $this->account_name,
            'accountNumber' => $this->account_number,

            // The frontend schema declares a printed branch name.
            'branch' => $this->whenLoaded(
                'branch',
                fn (): string => $this->branch_id === null ? '' : $this->branch->name,
                '',
            ),
            'branchId' => $this->branch_id === null ? null : (string) $this->branch_id,

            'currency' => $this->currency->value,
            'openingBalance' => $this->opening_balance,

            // From the ledger, never stored. Zero when the chart account has
            // not been loaded, which only happens on a write response where the
            // balance is not what the caller asked about.
            'balance' => $this->relationLoaded('chartAccount') && $this->chartAccount !== null
                ? $this->chartAccount->cachedBalance()->toDecimalString()
                : '0.00',

            'status' => $this->status->value,
            'description' => $this->description,

            'todayDeposit' => (string) ($this->today_deposit ?? '0.00'),
            'todayWithdrawal' => (string) ($this->today_withdrawal ?? '0.00'),

            'chartAccountId' => $this->chart_account_id === null ? null : (string) $this->chart_account_id,
            'chartAccountCode' => $this->whenLoaded('chartAccount', fn (): ?string => $this->chartAccount?->code),
        ];
    }
}
