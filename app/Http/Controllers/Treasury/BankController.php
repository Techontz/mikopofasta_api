<?php

declare(strict_types=1);

namespace App\Http\Controllers\Treasury;

use App\Domain\Treasury\Actions\DecideBankTransactionAction;
use App\Domain\Treasury\Actions\DeleteBankAccountAction;
use App\Domain\Treasury\Actions\RegisterBankAccountAction;
use App\Domain\Treasury\Actions\RequestBankTransactionAction;
use App\Domain\Treasury\Actions\RequestBankTransferAction;
use App\Domain\Treasury\Actions\UpdateBankAccountAction;
use App\Domain\Treasury\DTOs\BankAccountData;
use App\Domain\Treasury\Enums\BankTransactionStatus;
use App\Domain\Treasury\Enums\BankTransactionType;
use App\Domain\Treasury\Enums\BankTransferKind;
use App\Domain\Treasury\Policies\CapitalPolicy;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Treasury\Concerns\AuthorizesCapital;
use App\Http\Requests\Treasury\DecideBankTransactionRequest;
use App\Http\Requests\Treasury\StoreBankAccountRequest;
use App\Http\Requests\Treasury\StoreBankTransactionRequest;
use App\Http\Requests\Treasury\StoreBankTransferRequest;
use App\Http\Requests\Treasury\UpdateBankAccountRequest;
use App\Http\Resources\BankAccountResource;
use App\Http\Resources\BankTransactionResource;
use App\Http\Resources\BankTransferResource;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Branch;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Bank module — sidebar → Bank.
 *
 * Register Account, Account Balance, Bank Transaction, Approved Transaction and
 * the two Transfer Balance screens. Reads behind `treasury.view`, writes behind
 * `treasury.manage`, enforced by CapitalPolicy — the same pair Capital and
 * Headquarters use, because the frontend gates all three sections on it.
 *
 * See docs/modules/bank.md.
 */
final class BankController extends Controller
{
    use AuthorizesCapital;

    /**
     * GET /api/v1/bank-accounts?status=&branch_id=&with_movement=
     *
     * Register Account and Account Balance read the same list; the second asks
     * for `with_movement`, which adds what moved on each account today.
     */
    public function accounts(Request $request): JsonResponse
    {
        $this->authorizeCapital('view', $request);

        $accounts = BankAccount::query()
            ->with(['chartAccount.balances', 'branch'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->orderBy('bank_name')
            ->orderBy('account_name')
            ->get();

        if ($request->boolean('with_movement')) {
            $this->attachTodayMovement($accounts);
        }

        $total = Money::sum($accounts->map(fn (BankAccount $a): Money => $a->currentBalance()));

        return ApiResponse::data(
            BankAccountResource::collection($accounts),
            meta: ['totalBalance' => $total->toDecimalString()],
        );
    }

    /** POST /api/v1/bank-accounts */
    public function storeAccount(StoreBankAccountRequest $request, RegisterBankAccountAction $action): JsonResponse
    {
        $this->authorizeCapital('manage', $request);

        $account = $action->handle(BankAccountData::fromArray($request->validated()), $this->actor($request));

        return ApiResponse::data(new BankAccountResource($account), status: Response::HTTP_CREATED);
    }

    /** PUT /api/v1/bank-accounts/{bankAccount} */
    public function updateAccount(
        UpdateBankAccountRequest $request,
        BankAccount $bankAccount,
        UpdateBankAccountAction $action,
    ): JsonResponse {
        $this->authorizeCapital('manage', $request);

        $updated = $action->handle($bankAccount, BankAccountData::fromArray($request->validated()), $this->actor($request));

        return ApiResponse::data(new BankAccountResource($updated));
    }

    /** DELETE /api/v1/bank-accounts/{bankAccount} */
    public function destroyAccount(
        Request $request,
        BankAccount $bankAccount,
        DeleteBankAccountAction $action,
    ): JsonResponse {
        $this->authorizeCapital('manage', $request);

        $action->handle($bankAccount, $this->actor($request));

        return ApiResponse::data(['message' => "{$bankAccount->account_name} closed."]);
    }

    /** GET /api/v1/bank-transactions?status=&type=&bank_account_id=&from=&to= */
    public function transactions(Request $request): JsonResponse
    {
        $this->authorizeCapital('view', $request);

        $transactions = BankTransaction::query()
            ->withListRelations()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when(
                $request->filled('bank_account_id'),
                fn ($q) => $q->where('bank_account_id', $request->integer('bank_account_id')),
            )
            ->when($request->filled('from'), fn ($q) => $q->whereDate('transacted_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('transacted_on', '<=', $request->date('to')))
            ->orderByDesc('transacted_on')
            ->orderByDesc('id')
            ->get();

        $approved = $transactions->where('status', BankTransactionStatus::Approved);

        return ApiResponse::data(
            BankTransactionResource::collection($transactions),
            meta: [
                'total' => Money::sum($transactions->map(fn (BankTransaction $t): Money => $t->amount()))->toDecimalString(),
                // What actually moved, as against what was asked for.
                'approvedTotal' => Money::sum($approved->map(fn (BankTransaction $t): Money => $t->amount()))->toDecimalString(),
                'count' => $transactions->count(),
            ],
        );
    }

    /** POST /api/v1/bank-transactions */
    public function storeTransaction(
        StoreBankTransactionRequest $request,
        RequestBankTransactionAction $action,
    ): JsonResponse {
        $this->authorizeCapital('manage', $request);

        $validated = $request->validated();

        $transaction = $action->handle(
            BankAccount::query()->findOrFail($validated['bankAccountId']),
            BankTransactionType::from((string) $validated['type']),
            (string) $validated['amount'],
            isset($validated['branchId']) ? (int) $validated['branchId'] : null,
            $validated['note'] ?? null,
            $validated['transactedOn'] ?? null,
            $this->actor($request),
        );

        return ApiResponse::data(new BankTransactionResource($transaction), status: Response::HTTP_CREATED);
    }

    /** POST /api/v1/bank-transactions/{transaction}/decide */
    public function decideTransaction(
        DecideBankTransactionRequest $request,
        BankTransaction $transaction,
        DecideBankTransactionAction $action,
    ): JsonResponse {
        // §14: the requester may not approve their own movement.
        abort_unless(
            app(CapitalPolicy::class)->decideBankTransaction($this->actor($request), $transaction),
            Response::HTTP_FORBIDDEN,
        );

        $decided = $action->handle(
            $transaction,
            BankTransactionStatus::from((string) $request->validated('decision')),
            $request->validated('note'),
            $this->actor($request),
        );

        return ApiResponse::data(new BankTransactionResource($decided));
    }

    /** GET /api/v1/bank-transfers?kind=&status=&from=&to= */
    public function transfers(Request $request): JsonResponse
    {
        $this->authorizeCapital('view', $request);

        $transfers = \App\Models\BankTransfer::query()
            ->withListRelations()
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('transferred_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('transferred_on', '<=', $request->date('to')))
            ->orderByDesc('transferred_on')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::data(
            BankTransferResource::collection($transfers),
            meta: [
                'total' => Money::sum($transfers->map(fn ($t): Money => $t->amount()))->toDecimalString(),
                // Kept apart from the amount so the cost of banking is visible
                // rather than buried inside the transfers that incurred it.
                'chargesTotal' => Money::sum($transfers->map(fn ($t): Money => $t->chargeFee()))->toDecimalString(),
                'count' => $transfers->count(),
            ],
        );
    }

    /** POST /api/v1/bank-transfers */
    public function storeTransfer(StoreBankTransferRequest $request, RequestBankTransferAction $action): JsonResponse
    {
        $this->authorizeCapital('manage', $request);

        $validated = $request->validated();

        $transfer = $action->handle(
            BankTransferKind::from((string) $validated['kind']),
            BankAccount::query()->findOrFail($validated['fromAccountId']),
            isset($validated['toAccountId']) ? BankAccount::query()->find($validated['toAccountId']) : null,
            isset($validated['toBranchId']) ? Branch::query()->find($validated['toBranchId']) : null,
            (string) $validated['amount'],
            (string) ($validated['chargeFee'] ?? '0'),
            (string) $validated['reason'],
            $validated['description'] ?? null,
            $validated['reference'] ?? null,
            $validated['transferredOn'] ?? null,
            $this->actor($request),
        );

        return ApiResponse::data(new BankTransferResource($transfer), status: Response::HTTP_CREATED);
    }

    /**
     * Today's movement on each account, in one query rather than one per row.
     *
     * Read from the journal, not from `bank_transactions`: a bank account is
     * also credited by loan disbursement and debited by repayment, and a screen
     * that showed only what this module recorded would understate the day by
     * everything the rest of the system did.
     *
     * @param \Illuminate\Support\Collection<int, BankAccount> $accounts
     */
    private function attachTodayMovement($accounts): void
    {
        $chartIds = $accounts->pluck('chart_account_id')->filter()->all();

        if ($chartIds === []) {
            return;
        }

        $rows = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_id', $chartIds)
            ->whereDate('journal_entries.entry_date', Date::now()->toDateString())
            ->groupBy('journal_entry_lines.account_id')
            ->selectRaw('journal_entry_lines.account_id, SUM(debit_amount) AS deposit, SUM(credit_amount) AS withdrawal')
            ->get()
            ->keyBy('account_id');

        foreach ($accounts as $account) {
            $row = $rows->get($account->chart_account_id);

            // A debit to an asset account is money in; a credit is money out.
            $account->setAttribute('today_deposit', Money::of((string) ($row->deposit ?? '0'))->toDecimalString());
            $account->setAttribute('today_withdrawal', Money::of((string) ($row->withdrawal ?? '0'))->toDecimalString());
        }
    }
}
