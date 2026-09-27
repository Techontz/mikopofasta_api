<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Enums\Account;
use App\Enums\TransactionType;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Accounting\ReverseJournalRequest;
use App\Http\Resources\Api\V1\Accounting\JournalEntryResource;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalReversalGuard;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Accounting → Journal Entries. Read-only view of the ledger; the only correction is a reversal
 * with a reason (ACCOUNT OVERVIEW: "HAKUNA KUFUTA (DELETE) → NI REVERSAL TU", "POST /ledger/reverse").
 *
 * Manual adjusting entries are not offered: the Documents require every entry to come from a
 * business transaction and corrections to be reversals only.
 *
 * The generic reversal is limited to manual entries ({@see JournalReversalGuard}): an entry posted by a module
 * (loan, payment, payroll, dividend, capital, month-end close…) must be reversed from that module so its
 * dependent records are restored in the same transaction (Fund Flow Specification §21, §26).
 */
class JournalController extends ApiController
{
    /** Upper bound on rows returned to the client-side table. */
    private const LIMIT = 2000;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('accounting.view');

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'string'],
            'account' => ['nullable', 'string'],
            'reference' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'max:100'],
            'transaction_type' => ['nullable', Rule::enum(TransactionType::class)],
        ]);

        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString()) : CarbonImmutable::today()->startOfMonth();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString()) : CarbonImmutable::today();

        $query = $this->entries()
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->with(['branch:id,name', 'employee:id,first_name,middle_name,last_name', 'reversal:id,reversal_of_id,reference', 'reversalOf:id,reference'])
            ->withSum('lines as total', 'debit');

        $branch = $request->string('branch_id')->toString();
        if ($branch === 'hq') {
            $query->whereNull('branch_id');
        } elseif ($branch !== '' && $branch !== 'all') {
            $this->assertBranchAccessible((int) $branch);
            $query->where('branch_id', (int) $branch);
        }
        if ($request->filled('account')) {
            $query->whereHas('lines.account', fn (Builder $lines) => $lines->where('key', $request->string('account')->toString()));
        }
        if ($request->filled('reference')) {
            $query->where('reference', 'like', '%'.$request->string('reference')->toString().'%');
        }
        if ($request->filled('source')) {
            $source = $request->string('source')->toString();
            $source === 'manual' ? $query->whereNull('source_type') : $query->where('source_type', 'like', '%\\\\'.$source);
        }
        if ($request->filled('transaction_type')) {
            $query->where('transaction_type', $request->string('transaction_type')->toString());
        }

        return JournalEntryResource::collection($query->orderByDesc('entry_date')->orderByDesc('id')->limit(self::LIMIT)->get());
    }

    public function show(JournalEntry $journalEntry): JournalEntryResource
    {
        $this->authorizeAny('accounting.view');
        $entry = $this->findVisible($journalEntry);

        return new JournalEntryResource($entry->load([
            'branch:id,name', 'employee:id,first_name,middle_name,last_name', 'reversal:id,reversal_of_id,reference', 'reversalOf:id,reference',
            'lines.account.branch:id,name', 'lines.account.bankAccount:id,name', 'lines.account.employee:id,first_name,middle_name,last_name', 'lines.account.expenseType:id,name',
        ])->loadSum('lines as total', 'debit'));
    }

    /**
     * Distinct source types for the filter dropdown.
     */
    public function sources(): JsonResponse
    {
        $this->authorizeAny('accounting.view');

        $sources = $this->entries()->whereNotNull('source_type')->distinct()->pluck('source_type')
            ->map(fn (string $type): array => ['value' => class_basename($type), 'label' => JournalEntryResource::sourceLabel($type)])
            ->unique('value')->sortBy('label')->values();

        return response()->json(['data' => $sources]);
    }

    /**
     * Transaction types for the filter dropdown.
     */
    public function transactionTypes(): JsonResponse
    {
        $this->authorizeAny('accounting.view');

        return response()->json(['data' => TransactionType::options()]);
    }

    public function reverse(ReverseJournalRequest $request, JournalEntry $journalEntry, Ledger $ledger, JournalReversalGuard $guard): JsonResponse
    {
        $this->authorizeAny('accounting.reverse');
        $entry = $this->findVisible($journalEntry);
        $reason = $request->string('reason')->toString();

        try {
            $reversal = DB::transaction(function () use ($ledger, $guard, $entry, $reason, $request): JournalEntry {
                $locked = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
                $guard->assertReversible($locked);
                app(SegregationOfDuties::class)->assertCanReverse($locked, $this->currentEmployee());
                $reversal = $ledger->reverse($locked, $reason);

                AuditLog::create([
                    'company_id' => $entry->company_id,
                    'employee_id' => $this->currentEmployee()->id,
                    'action' => 'JournalEntry.reversed',
                    'auditable_type' => $entry->getMorphClass(),
                    'auditable_id' => $entry->id,
                    'before' => ['reference' => $entry->reference],
                    'after' => ['reversal_reference' => $reversal->reference, 'reason' => $reason],
                    'ip_address' => $request->ip(),
                ]);

                return $reversal;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->message($exception->getMessage(), 422, ['errors' => ['reason' => [$exception->getMessage()]]]);
        }

        return $this->message('Transaction Reversed successfully', 200, ['data' => new JournalEntryResource($reversal->loadSum('lines as total', 'debit'))]);
    }

    /**
     * Company/branch-scoped entries; entries touching Capital are hidden without capital.view.
     *
     * @return Builder<JournalEntry>
     */
    private function entries(): Builder
    {
        $query = $this->scoped(JournalEntry::query());

        if (! Gate::allows('capital.view')) {
            $query->whereDoesntHave('lines.account', fn (Builder $lines) => $lines->where('key', Account::Capital->value));
        }

        return $query;
    }

    private function findVisible(JournalEntry $entry): JournalEntry
    {
        return $this->entries()->whereKey($entry->id)->firstOrFail();
    }
}
