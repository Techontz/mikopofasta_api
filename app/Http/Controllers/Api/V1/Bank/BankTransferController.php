<?php

namespace App\Http\Controllers\Api\V1\Bank;

use App\Enums\Account;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Bank\CompanyFundTransferRequest;
use App\Http\Resources\Api\V1\Bank\BankTransferResource;
use App\Models\BankTransfer;
use App\Services\CompanyFunds;
use App\Services\Ledger;
use App\Services\Reports\Financial\CashAccounts;
use App\Services\TransferReversal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The bank money movements the owners and Finance run: Company Cash ↔ Bank (COMPANY ACCOUNT ↔ bank account),
 * the two reserve legs, petty cash to a branch, and the shared approve / reject / reverse decisions
 * ({@see CompanyFunds}).
 *
 * There is no branch → bank sweep: a branch holds no money of its own beyond the petty cash HQ sends it, so
 * there is nothing at a branch to sweep into a company bank account.
 */
class BankTransferController extends ApiController
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * Approve a pending bank movement of any type (company cash ↔ bank, reserve → investment, reserve → principal, petty cash) and post
     * it ({@see CompanyFunds::approve()}). The initiator cannot approve their own transfer (rule 6).
     */
    public function approve(BankTransfer $bankTransfer, CompanyFunds $funds): JsonResponse
    {
        $this->authorizeDecision($bankTransfer);
        $this->assertTransferVisible($bankTransfer);

        $funds->approve($bankTransfer, $this->currentEmployee());

        return $this->message('Transaction Approved successfully');
    }

    /**
     * Reject a pending bank movement (nothing was posted); the row is kept with the reason.
     */
    public function reject(Request $request, BankTransfer $bankTransfer, CompanyFunds $funds): JsonResponse
    {
        $this->authorizeDecision($bankTransfer);
        $this->assertTransferVisible($bankTransfer);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $funds->reject($bankTransfer, $validated['reason'], $this->currentEmployee());

        return $this->message('Transaction Rejected successfully');
    }

    /**
     * HQ reserve → Investment RESERVE A/C transfers, with the HQ reserve still to send and the Investment RESERVE A/C balance.
     * Readable by Finance (the sender) and by bank.manage (the owners, following the link from Pending Approvals).
     */
    public function reserveToInvestmentIndex(Request $request, CashAccounts $cash): JsonResponse
    {
        $this->authorizeAny('bank.manage', 'funds.transfer');
        $companyId = $this->currentEmployee()->company_id;

        return $this->collection(
            $this->applyFilters($this->transfers(CompanyFunds::RESERVE_TO_INVESTMENT), $request->merge(['branch_id' => null]), 'transfer_date'),
            ['hq_reserve_balance' => $cash->hqReserve($companyId), 'investment_reserve_balance' => $this->ledger->balance($companyId, Account::InvestmentReserve) + 0.0],
        );
    }

    /**
     * Request HQ reserve → Investment RESERVE A/C (pending). Posted only when another authorised user approves.
     * Sending is Finance's own leg (funds.transfer): the owners approve the request, they never raise it, so bank.manage
     * alone (Super Admin, Admin) opens the list above but not this — the same split as petty cash.
     */
    public function reserveToInvestmentStore(Request $request, CompanyFunds $funds): JsonResponse
    {
        $this->authorizeAny('funds.transfer');
        $validated = $request->validate(['amount' => ['required', 'numeric', 'min:1']]);

        $transfer = $funds->requestReserveToInvestment($this->currentEmployee()->company_id, (float) $validated['amount'], $this->currentEmployee());

        return $this->message('Transaction Requested successfully — awaiting approval by another authorised user', 201, ['data' => new BankTransferResource($transfer->load(['employee']))]);
    }

    /**
     * Investment RESERVE A/C → OPERATION PRINCIPAL: the second leg of the reserve chain, for the owners only (capital.manage).
     * The Investment can send only what Finance has already sent it.
     */
    public function reserveToPrincipalIndex(Request $request): JsonResponse
    {
        $this->authorizeAny('capital.manage');
        $companyId = $this->currentEmployee()->company_id;

        return $this->collection(
            $this->applyFilters($this->transfers(CompanyFunds::RESERVE_TO_PRINCIPAL), $request->merge(['branch_id' => null]), 'transfer_date'),
            [
                'investment_reserve_balance' => $this->ledger->balance($companyId, Account::InvestmentReserve) + 0.0,
                'operation_principal_balance' => $this->ledger->balance($companyId, Account::Principal) + 0.0,
            ],
        );
    }

    public function reserveToPrincipalStore(Request $request, CompanyFunds $funds): JsonResponse
    {
        $this->authorizeAny('capital.manage');
        $validated = $request->validate(['amount' => ['required', 'numeric', 'min:1']]);

        $transfer = $funds->requestReserveToPrincipal($this->currentEmployee()->company_id, (float) $validated['amount'], $this->currentEmployee());

        return $this->message('Transaction Requested successfully — awaiting approval by another authorised user', 201, ['data' => new BankTransferResource($transfer->load(['employee']))]);
    }

    /**
     * Petty cash sent to branches, with the HQ interest income still available and each branch's PETTY CASH A/C balance.
     * Readable by Finance (the sender) and by bank.manage (the approver following the link from Pending Approvals).
     */
    public function pettyCashIndex(Request $request, CashAccounts $cash): JsonResponse
    {
        $this->authorizeAny('bank.manage', 'funds.transfer');
        $companyId = $this->currentEmployee()->company_id;

        return $this->collection(
            $this->applyFilters($this->transfers(CompanyFunds::PETTY_CASH_TO_BRANCH), $request, 'transfer_date'),
            [
                'hq_interest_balance' => $cash->hqInterest($companyId),
                // HQ is not a branch — it is the sender of petty cash, never a recipient.
                'branches' => $this->visibleBranches()->reject(fn ($branch): bool => (bool) $branch->is_head_office)->map(fn ($branch): array => [
                    'id' => (int) $branch->id,
                    'name' => $branch->name,
                    'petty_cash' => $this->ledger->balance($companyId, Account::PettyCash, $branch->id) + 0.0,
                ])->values(),
            ],
        );
    }

    /**
     * Request HQ interest income → a branch PETTY CASH A/C (pending). The branch then spends it only on expenses HQ approves.
     * Sending is Finance's own leg (funds.transfer): the owners approve the request, they never raise it, so bank.manage
     * alone (Super Admin, Admin) opens the list above but not this.
     */
    public function pettyCashStore(Request $request, CompanyFunds $funds): JsonResponse
    {
        $this->authorizeAny('funds.transfer');
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('company_id', $this->currentEmployee()->company_id)->where('is_head_office', false)],
            'amount' => ['required', 'numeric', 'min:1'],
        ], ['branch_id.exists' => 'Petty cash can only be sent to a branch, not Head Office.']);

        $transfer = $funds->requestPettyCash($this->currentEmployee()->company_id, (int) $validated['branch_id'], (float) $validated['amount'], $this->currentEmployee());

        return $this->message('Petty cash Requested successfully — awaiting approval by another authorised user', 201, ['data' => new BankTransferResource($transfer->load(['branch', 'employee']))]);
    }

    /**
     * Company Cash ↔ Bank: movements between the COMPANY ACCOUNT and company bank accounts (both directions).
     */
    public function companyIndex(Request $request): JsonResponse
    {
        $this->authorizeAny('bank.manage');
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $query = BankTransfer::query()
            ->where('company_id', $this->currentEmployee()->company_id)
            ->whereIn('type', [CompanyFunds::CASH_TO_BANK, CompanyFunds::BANK_TO_CASH])
            ->with(['bankAccount', 'employee', 'approver', 'rejectedBy', 'journalEntry', 'reversedBy', 'reversalJournalEntry'])
            ->latest('id');

        $transfers = $this->applyFilters($query, $request->merge(['branch_id' => null]), 'transfer_date')->get();
        $posted = $transfers->where('status', CompanyFunds::APPROVED);

        return response()->json([
            'data' => BankTransferResource::collection($transfers),
            'total' => round((float) $posted->sum('amount'), 2),
            'total_pending' => round((float) $transfers->where('status', CompanyFunds::PENDING)->sum('amount'), 2),
            'total_reversed' => round((float) $transfers->where('status', TransferReversal::STATUS_REVERSED)->sum('amount'), 2),
            'company_cash_balance' => $this->ledger->balance($this->currentEmployee()->company_id, Account::Company) + 0.0,
        ]);
    }

    public function companyStore(CompanyFundTransferRequest $request, CompanyFunds $funds): JsonResponse
    {
        $this->authorizeAny('bank.manage');

        $result = $funds->transfer(
            $this->currentEmployee()->company_id,
            $request->string('direction')->toString(),
            $request->integer('bank_account_id'),
            $request->float('amount'),
            $this->currentEmployee(),
            $request->input('reference'),
            $request->input('idempotency_key'),
        );

        return $this->message(
            $result['created'] ? 'Transfer Requested successfully — awaiting approval by another authorised user' : 'Transfer was already recorded',
            $result['created'] ? 201 : 200,
            ['data' => new BankTransferResource($result['transfer']->load(['bankAccount', 'employee', 'journalEntry']))],
        );
    }

    /**
     * Reverse a posted bank transfer of any type (bank → branch, bank → HQ, company cash ↔ bank): the
     * journal is mirrored exactly (charges included) and the row is kept with status "reversed".
     */
    public function reverse(Request $request, BankTransfer $bankTransfer, TransferReversal $reversals): JsonResponse
    {
        $this->authorizeAny('bank.manage');
        $this->authorizeAny('accounting.reverse');
        $this->assertTransferVisible($bankTransfer);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $transfer = $reversals->reverse($bankTransfer, $validated['reason'], $this->currentEmployee());

        return $this->message('Transaction Reversed successfully', 200, ['data' => new BankTransferResource($transfer->load(['branch', 'bankAccount', 'employee', 'journalEntry', 'reversedBy', 'reversalJournalEntry']))]);
    }

    /**
     * @return Builder<BankTransfer>
     */
    private function transfers(string $type): Builder
    {
        $query = BankTransfer::query()->where('type', $type)->with(['branch', 'bankAccount', 'employee', 'approver', 'rejectedBy', 'journalEntry', 'reversedBy', 'reversalJournalEntry'])->latest('id');

        return in_array($type, [CompanyFunds::RESERVE_TO_INVESTMENT, CompanyFunds::RESERVE_TO_PRINCIPAL], true)
            ? $query->where('company_id', $this->currentEmployee()->company_id)
            : $this->scoped($query);
    }

    /**
     * @param  Builder<BankTransfer>  $query
     * @param  array<string, mixed>  $extra
     */
    private function collection(Builder $query, array $extra = []): JsonResponse
    {
        $transfers = $query->get();
        $posted = $transfers->where('status', CompanyFunds::APPROVED);

        return response()->json([
            'data' => BankTransferResource::collection($transfers),
            'total' => round((float) $posted->sum('amount'), 2),
            'total_pending' => round((float) $transfers->where('status', CompanyFunds::PENDING)->sum('amount'), 2),
            'total_charge' => round((float) $posted->sum('charge'), 2),
            'total_reversed' => round((float) $transfers->where('status', TransferReversal::STATUS_REVERSED)->sum('amount'), 2),
            ...$extra,
        ]);
    }

    /**
     * Both reserve legs (HQ → Investment, Investment → OPERATION PRINCIPAL) are decided only by Super Admin, Admin or a
     * shareholder; every other type needs bank.manage.
     */
    private function authorizeDecision(BankTransfer $bankTransfer): void
    {
        if (in_array($bankTransfer->type, [CompanyFunds::RESERVE_TO_INVESTMENT, CompanyFunds::RESERVE_TO_PRINCIPAL], true)) {
            abort_unless(CompanyFunds::canDecideReserve($this->currentEmployee()), 403, CompanyFunds::RESERVE_APPROVER_MESSAGE);

            return;
        }

        $this->authorizeAny('bank.manage');
    }

    private function assertTransferVisible(BankTransfer $bankTransfer): void
    {
        abort_unless((int) $bankTransfer->company_id === (int) $this->currentEmployee()->company_id, 404);
        if ($bankTransfer->branch_id !== null) {
            $this->assertBranchAccessible($bankTransfer->branch_id);
        }
    }
}
