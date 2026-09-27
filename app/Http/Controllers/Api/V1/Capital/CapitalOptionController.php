<?php

namespace App\Http\Controllers\Api\V1\Capital;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\BankAccount;
use App\Models\ShareHolder;
use Illuminate\Http\JsonResponse;

/**
 * {value,label} dropdown lists used by the Capital pages.
 */
class CapitalOptionController extends ApiController
{
    public function shareHolders(): JsonResponse
    {
        $this->authorizeAny('capital.view', 'capital.manage');

        return response()->json(['data' => ShareHolder::where('company_id', $this->currentEmployee()->company_id)->orderBy('id')->get()
            ->map(fn (ShareHolder $holder): array => ['value' => (string) $holder->id, 'label' => $holder->full_name])]);
    }

    public function bankAccounts(): JsonResponse
    {
        $this->authorizeAny('capital.manage');

        return response()->json(['data' => BankAccount::where('company_id', $this->currentEmployee()->company_id)->orderBy('id')->get()
            ->map(fn (BankAccount $account): array => ['value' => (string) $account->id, 'label' => $account->name.' - '.number_format($account->balance())])]);
    }
}
