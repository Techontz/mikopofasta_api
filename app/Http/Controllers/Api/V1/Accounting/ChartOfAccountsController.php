<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Enums\Account;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Accounting → Chart of Accounts (ACCOUNT OVERVIEW §2 "Master ledger structure").
 *
 * Every documented account is listed by type with its real-time, normal-balance-aware balance.
 * Scoped rows (per branch, bank, staff member or expense category) are the sub-ledgers.
 * Handwritten notes: Finance sees Principal, but Capital is visible only to holders of capital.view.
 */
class ChartOfAccountsController extends ApiController
{
    /**
     * @var array<string, string>
     */
    private const TYPES = ['asset' => 'ASSETS', 'liability' => 'LIABILITIES', 'equity' => 'EQUITY / CAPITAL', 'income' => 'INCOME', 'expense' => 'EXPENSES'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('accounting.view');

        $request->validate([
            'as_of' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'string'],
        ]);

        $asOf = $request->filled('as_of') ? CarbonImmutable::parse($request->string('as_of')->toString()) : CarbonImmutable::today();
        $branch = $request->string('branch_id')->toString();
        $canSeeCapital = Gate::allows('capital.view');

        $query = $this->scoped(LedgerAccount::query())->with(['branch:id,name', 'bankAccount:id,name', 'employee:id,first_name,middle_name,last_name', 'expenseType:id,name']);
        if ($branch === 'hq') {
            $query->whereNull('branch_id');
        } elseif ($branch !== '' && $branch !== 'all') {
            $this->assertBranchAccessible((int) $branch);
            $query->where('branch_id', (int) $branch);
        }
        if (! $canSeeCapital) {
            $query->where('key', '!=', Account::Capital->value);
        }
        $accounts = $query->orderBy('code')->orderBy('branch_id')->get();

        $totals = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.account_id', $accounts->modelKeys())
            ->whereDate('journal_entries.entry_date', '<=', $asOf->toDateString())
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) AS debits, SUM(journal_lines.credit) AS credits')
            ->toBase()
            ->get()
            ->keyBy('account_id');

        $byKey = $accounts->groupBy(fn (LedgerAccount $account): string => $account->key->value);

        $tree = [];
        foreach (self::TYPES as $type => $label) {
            $groups = [];
            foreach (Account::cases() as $key) {
                if ($key->type() !== $type || ($key === Account::Capital && ! $canSeeCapital)) {
                    continue;
                }

                $children = ($byKey[$key->value] ?? collect())->map(function (LedgerAccount $account) use ($key, $totals): array {
                    $row = $totals->get($account->id);
                    $net = (float) ($row->debits ?? 0) - (float) ($row->credits ?? 0);

                    return [
                        'id' => $account->id,
                        'code' => $account->code,
                        'name' => $account->name,
                        'scope' => $this->scopeLabel($account),
                        'branch_id' => $account->branch_id,
                        'debits' => round((float) ($row->debits ?? 0), 2),
                        'credits' => round((float) ($row->credits ?? 0), 2),
                        'balance' => round($key->isDebitNormal() ? $net : -$net, 2),
                    ];
                })->values();

                $groups[] = [
                    'key' => $key->value,
                    'code' => $key->code(),
                    'name' => $key->label(),
                    'normal_balance' => $key->isDebitNormal() ? 'debit' : 'credit',
                    'balance' => round((float) $children->sum('balance'), 2),
                    'children' => $children,
                ];
            }

            $tree[] = [
                'type' => $type,
                'label' => $label,
                'balance' => round(array_sum(array_column($groups, 'balance')), 2),
                'accounts' => $groups,
            ];
        }

        return response()->json(['data' => $tree, 'as_of' => $asOf->toDateString()]);
    }

    /**
     * {value,label} list of account keys for the journal filter.
     */
    public function options(): JsonResponse
    {
        $this->authorizeAny('accounting.view');
        $canSeeCapital = Gate::allows('capital.view');

        $options = collect(Account::cases())
            ->reject(fn (Account $account): bool => $account === Account::Capital && ! $canSeeCapital)
            ->map(fn (Account $account): array => ['value' => $account->value, 'label' => $account->code().' - '.$account->label()])
            ->values();

        return response()->json(['data' => $options]);
    }

    private function scopeLabel(LedgerAccount $account): string
    {
        $parts = array_filter([
            $account->branch?->name,
            $account->bankAccount?->name,
            $account->employee?->full_name,
            $account->expenseType?->name,
        ]);

        return $parts === [] ? 'HQ' : implode(' / ', $parts);
    }
}
