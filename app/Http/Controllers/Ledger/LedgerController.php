<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ledger;

use App\Domain\Ledger\Actions\ReverseJournalEntryAction;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Repayments\ReverseEntryRequest;
use App\Http\Resources\ChartOfAccountResource;
use App\Http\Resources\JournalEntryLineResource;
use App\Http\Resources\JournalEntryResource;
use App\Http\Resources\ReversalRequestResource;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ledger — §15.4.
 *
 * Read-only apart from the reversal workflow. There is no endpoint that posts
 * an entry: entries are a consequence of a business event, and LedgerService
 * is the only writer (§5). An API that let a user hand-write a journal entry
 * would make the ledger something other than a record of what happened.
 */
final class LedgerController extends Controller
{
    /**
     * GET /api/v1/ledger/accounts — chart of accounts with live balances.
     */
    public function accounts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', JournalEntry::class);

        $accounts = ChartOfAccount::query()
            ->with('balances')
            ->orderBy('code')
            ->get();

        return ApiResponse::data(ChartOfAccountResource::collection($accounts));
    }

    /**
     * GET /api/v1/ledger/accounts/{account}/entries — §15.4.
     *
     * The account's own lines with a running balance on its normal side.
     */
    public function accountEntries(Request $request, ChartOfAccount $account, TrialBalanceBuilder $trialBalance): JsonResponse
    {
        $this->authorize('viewAny', JournalEntry::class);

        $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;

        return ApiResponse::data(
            $trialBalance->accountLedger($account, $branchId),
            ['account' => new ChartOfAccountResource($account->load('balances'))],
        );
    }

    /**
     * GET /api/v1/ledger/entries
     */
    public function entries(Request $request): JsonResponse
    {
        $this->authorize('viewAny', JournalEntry::class);

        $query = JournalEntry::query()
            ->with('lines.account')
            ->when($request->filled('source_type'), fn ($q) => $q->where('source_type', $request->string('source_type')))
            ->when($request->filled('loan_id'), fn ($q) => $q->whereHas('lines', fn ($l) => $l->where('loan_id', $request->integer('loan_id'))))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('entry_date', '>=', $request->string('from')->toString()))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('entry_date', '<=', $request->string('to')->toString()))
            ->latest('posted_at');

        return ApiResponse::paginated(
            $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString(),
            JournalEntryResource::class,
        );
    }

    /**
     * GET /api/v1/ledger/entries/{entry}
     */
    public function entry(Request $request, JournalEntry $entry): JsonResponse
    {
        $this->authorize('view', $entry);

        return ApiResponse::data(new JournalEntryResource($entry->load('lines.account')));
    }

    /**
     * GET /api/v1/ledger/trial-balance
     *
     * Recomputed from the lines every call, never read from the balance cache
     * — a report that proves the books balance must not be reading a summary
     * it cannot itself verify.
     */
    public function trialBalance(Request $request, TrialBalanceBuilder $builder): JsonResponse
    {
        $this->authorize('viewAny', JournalEntry::class);

        $result = $builder->build(
            $request->filled('branch_id') ? $request->integer('branch_id') : null,
            $request->filled('to') ? $request->string('to')->toString() : null,
        );

        return ApiResponse::data($result['rows'], [
            'totalDebits' => $result['totalDebits'],
            'totalCredits' => $result['totalCredits'],
            'balanced' => $result['balanced'],
        ]);
    }

    /**
     * GET /api/v1/ledger/{dimension}/{id} — §15.4's sub-ledgers.
     *
     * §2.7: customer, loan, staff and branch "ledgers" are NOT separate tables
     * — they are journal lines filtered by the matching dimension. One
     * endpoint therefore covers all four, rather than four near-identical ones.
     */
    public function subLedger(Request $request, string $dimension, int $id): JsonResponse
    {
        $this->authorize('viewAny', JournalEntry::class);

        $column = match ($dimension) {
            'customers' => 'customer_id',
            'loans' => 'loan_id',
            'staff' => 'staff_profile_id',
            'branches' => 'branch_id',
            default => abort(Response::HTTP_NOT_FOUND),
        };

        $lines = JournalEntryLine::query()
            ->with(['account', 'entry'])
            ->where($column, $id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->orderBy('journal_entries.posted_at')
            ->select('journal_entry_lines.*')
            ->get();

        $debits = \App\Support\Money::sum($lines->map(fn (JournalEntryLine $l) => $l->debitAmount()));
        $credits = \App\Support\Money::sum($lines->map(fn (JournalEntryLine $l) => $l->creditAmount()));

        return ApiResponse::data(JournalEntryLineResource::collection($lines), [
            'dimension' => $dimension,
            'id' => (string) $id,
            'totalDebits' => $debits->toDecimalString(),
            'totalCredits' => $credits->toDecimalString(),
            'net' => $debits->subtract($credits)->toDecimalString(),
        ]);
    }

    /**
     * POST /api/v1/ledger/entries/{entry}/reverse — §15.4.
     * Requires `ledger.reverse.request`.
     */
    public function requestReversal(ReverseEntryRequest $request, JournalEntry $entry, ReverseJournalEntryAction $action): JsonResponse
    {
        $this->authorize('requestReversal', $entry);

        $reversal = $action->request($entry, (string) $request->validated('reason'), $this->actor($request));

        return ApiResponse::data(
            new ReversalRequestResource($reversal->load('journalEntry')),
            status: Response::HTTP_CREATED,
        );
    }

    /**
     * GET /api/v1/ledger/reversals
     */
    public function reversals(Request $request): JsonResponse
    {
        $this->authorize('viewAny', JournalEntry::class);

        $requests = ReversalRequest::query()
            ->with(['journalEntry', 'reversalEntry'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->get();

        return ApiResponse::data(ReversalRequestResource::collection($requests));
    }

    /**
     * POST /api/v1/ledger/reversals/{reversalRequest}/approve — §15.4.
     * Requires `ledger.reverse.approve`, a different grant from requesting.
     */
    public function approveReversal(Request $request, ReversalRequest $reversalRequest, ReverseJournalEntryAction $action): JsonResponse
    {
        $this->authorize('approveReversal', JournalEntry::class);

        $decided = $action->approve($reversalRequest, $this->actor($request));

        return ApiResponse::data(new ReversalRequestResource($decided->load(['journalEntry', 'reversalEntry'])));
    }

    /**
     * POST /api/v1/ledger/reversals/{reversalRequest}/reject
     */
    public function rejectReversal(Request $request, ReversalRequest $reversalRequest, ReverseJournalEntryAction $action): JsonResponse
    {
        $this->authorize('approveReversal', JournalEntry::class);

        $decided = $action->reject(
            $reversalRequest,
            $request->string('note')->toString() ?: null,
            $this->actor($request),
        );

        return ApiResponse::data(new ReversalRequestResource($decided->load('journalEntry')));
    }
}
