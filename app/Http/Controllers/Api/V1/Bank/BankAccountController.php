<?php

namespace App\Http\Controllers\Api\V1\Bank;

use App\Enums\Account;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Bank\BankAccountRequest;
use App\Http\Resources\Api\V1\Bank\BankAccountResource;
use App\Models\BankAccount;
use App\Services\Ledger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Bank → Register Account (live admin/banking_account) and Account Balance (admin/bank_balance).
 */
class BankAccountController extends ApiController
{
    public function __construct(private readonly Ledger $ledger) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorizeAny('bank.manage');

        return BankAccountResource::collection($this->accounts());
    }

    /**
     * Registers the account; an opening balance is posted Dr BANK / Cr CAPITAL via Ledger::openingBalance.
     */
    public function store(BankAccountRequest $request): JsonResponse
    {
        $this->authorizeAny('bank.manage');

        $account = DB::transaction(function () use ($request): BankAccount {
            $account = BankAccount::create([
                'company_id' => $this->currentEmployee()->company_id,
                'name' => $request->string('ac_name')->toString(),
            ]);

            if ($request->float('opening_balance') > 0) {
                $this->ledger->openingBalance($account->company_id, Account::Bank, $request->float('opening_balance'), 'OPENING BALANCE - '.$account->name, reference: $account, bankAccount: $account);
            }

            return $account;
        });

        return $this->message('Account Registered successfully', 201, ['data' => new BankAccountResource($account)]);
    }

    public function update(BankAccountRequest $request, BankAccount $bankAccount): JsonResponse
    {
        $this->authorizeAny('bank.manage');

        $bankAccount->update(['name' => $request->string('ac_name')->toString()]);

        return $this->message('Account Updated successfully', 200, ['data' => new BankAccountResource($bankAccount)]);
    }

    public function destroy(BankAccount $bankAccount): JsonResponse
    {
        $this->authorizeAny('bank.manage');

        if ($bankAccount->ledgerAccounts()->whereHas('lines')->exists()) {
            return $this->message('Account has transactions and cannot be deleted', 422);
        }

        $bankAccount->ledgerAccounts()->delete();
        $bankAccount->delete();

        return $this->message('Account Deleted successfully');
    }

    /**
     * Account Balance: real-time ledger balance of every bank account.
     */
    public function balances(): JsonResponse
    {
        $this->authorizeAny('bank.manage');

        $rows = $this->accounts()->map(fn (BankAccount $account): array => ['id' => $account->id, 'name' => $account->name, 'balance' => $account->balance()]);

        return response()->json(['data' => $rows, 'total' => round($rows->sum('balance'), 2)]);
    }

    /**
     * Dropdown "NMB - 16,200" used by the bank transfer and bank expense modals.
     */
    public function options(): JsonResponse
    {
        $this->authorizeAny('bank.manage');

        return response()->json(['data' => $this->accounts()->map(fn (BankAccount $account): array => [
            'value' => (string) $account->id,
            'label' => $account->name.' - '.number_format($account->balance()),
        ])]);
    }

    /**
     * @return Collection<int, BankAccount>
     */
    private function accounts(): Collection
    {
        return BankAccount::where('company_id', $this->currentEmployee()->company_id)->orderBy('id')->get();
    }
}
