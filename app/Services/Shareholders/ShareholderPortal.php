<?php

namespace App\Services\Shareholders;

use App\Models\BankAccount;
use App\Models\Capital;
use App\Models\Company;
use App\Models\DividendAllocation;
use App\Models\DividendPayment;
use App\Models\ShareHolder;
use App\Models\ShareTransaction;
use App\Services\ShareholderOwnership;
use App\Services\Shares\ShareRegister;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read models of the Shareholder Portal. Every figure is derived from the authoritative records — capital contributions
 * (`capitals`, posted and not reversed), the share register (ownership) and dividend allocations / posted payments — no
 * balance is stored here. Methods taking a {@see ShareHolder} return only that shareholder's data; {@see directory()}
 * returns the public holdings of every shareholder through a strict field whitelist.
 */
class ShareholderPortal
{
    public function __construct(
        private readonly ShareholderOwnership $ownership,
        private readonly ShareRegister $register,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(ShareHolder $holder): array
    {
        $companyId = (int) $holder->company_id;
        $holding = $this->ownership->forShareHolder($holder);
        $dividends = $this->dividendTotals($holder);

        return [
            'share_holder' => ['id' => $holder->id, 'holder_number' => $holder->holder_number, 'name' => $holder->full_name],
            'my_shares' => $holding['shares'],
            'my_ownership_percent' => $holding['ownership_percent'],
            'my_capital' => $holding['total_contributed'],
            'my_capital_breakdown' => ['cash' => $holding['cash_contributed'], 'bank' => $holding['bank_contributed'], 'asset' => $holding['asset_contributed']],
            'share_value' => $holding['share_value'],
            'holding_value' => $holding['holding_value'],
            'total_company_shares' => $this->register->issuedShares($companyId),
            'total_shareholder_capital' => $this->ownership->totalContributed($companyId),
            'dividends' => $dividends,
            'pending_contributions' => $holder->capitals()->where('status', Capital::STATUS_PENDING)->count(),
            'pending_contributions_amount' => round((float) $holder->capitals()->where('status', Capital::STATUS_PENDING)->sum('amount'), 2),
        ];
    }

    /**
     * My contributions of every status (pending, posted, rejected, cancelled, reversed), newest first.
     *
     * @return array{total_contributed: float, pending_amount: float, rows: Collection<int, array<string, mixed>>}
     */
    public function contributions(ShareHolder $holder): array
    {
        $rows = $holder->capitals()->with(['bankAccount', 'journalEntry'])->orderByDesc('id')->get();

        return [
            'total_contributed' => round((float) $rows->filter(fn (Capital $capital): bool => $capital->isPosted() && ! $capital->isReversed())->sum('amount'), 2),
            'pending_amount' => round((float) $rows->where('status', Capital::STATUS_PENDING)->sum('amount'), 2),
            'rows' => $rows->map(fn (Capital $capital): array => $this->presentContribution($capital))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentContribution(Capital $capital): array
    {
        return [
            'id' => $capital->id,
            'date' => ($capital->contributed_at ?? $capital->created_at)?->format('Y-m-d H:i:s'),
            'amount' => (float) $capital->amount,
            'payment_method' => $capital->pay_method,
            'bank_account' => $capital->pay_method === 'BANK' ? $capital->bankAccount?->name : null,
            'reference' => $capital->receipt_number,
            'status' => $capital->isReversed() ? 'reversed' : ($capital->status ?? Capital::STATUS_POSTED),
            'source' => $capital->isFromShareholderPortal() ? 'shareholder' : 'staff',
            'rejection_reason' => $capital->rejection_reason,
            'journal_reference' => $capital->isPosted() ? $capital->journalEntry?->reference : null,
            'approved_at' => $capital->approved_at?->toDateTimeString(),
            'rejected_at' => $capital->rejected_at?->toDateTimeString(),
            'cancelled_at' => $capital->cancelled_at?->toDateTimeString(),
            'reversed_at' => $capital->reversed_at?->toDateTimeString(),
            'has_receipt' => $capital->receipt_file !== null,
            'receipt_endpoint' => $capital->receipt_file ? "portal/shareholder/capital/{$capital->id}/receipt" : null,
            'can_cancel' => $capital->isPending() && $capital->isFromShareholderPortal(),
        ];
    }

    /**
     * My current holding and my share-register movements.
     *
     * @return array<string, mixed>
     */
    public function shares(ShareHolder $holder): array
    {
        $holding = $this->ownership->forShareHolder($holder);
        $transactions = ShareTransaction::where('company_id', $holder->company_id)
            ->where(fn ($query) => $query->where('from_share_holder_id', $holder->id)->orWhere('to_share_holder_id', $holder->id))
            ->with(['fromShareHolder', 'toShareHolder'])
            ->orderByDesc('transacted_at')->orderByDesc('id')
            ->get();

        return [
            'shares' => $holding['shares'],
            'total_shares' => $holding['total_shares'],
            'ownership_percent' => $holding['ownership_percent'],
            'share_value' => $holding['share_value'],
            'holding_value' => $holding['holding_value'],
            'history' => $this->register->holdingHistory($holder),
            'transactions' => $transactions->map(fn (ShareTransaction $row): array => $this->presentShareTransaction($row, $holder))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentShareTransaction(ShareTransaction $row, ShareHolder $holder): array
    {
        $incoming = (int) $row->to_share_holder_id === $holder->id;
        $counterparty = $incoming ? $row->fromShareHolder : $row->toShareHolder;

        return [
            'id' => $row->id,
            'reference' => $row->reference,
            'type' => $row->type->value,
            'type_label' => $row->type->label(),
            'direction' => $incoming ? 'in' : 'out',
            'shares' => (int) $row->shares,
            'signed_shares' => $incoming ? (int) $row->shares : -(int) $row->shares,
            'share_value' => (float) $row->share_value,
            'price_per_share' => $row->price_per_share === null ? null : (float) $row->price_per_share,
            'total_amount' => $row->total_amount === null ? null : (float) $row->total_amount,
            'counterparty' => $counterparty?->full_name ?? 'Company',
            'transacted_at' => $row->transacted_at?->format('Y-m-d H:i:s'),
            'status' => $row->status,
        ];
    }

    /**
     * My dividend entitlements per declaration with their payments.
     *
     * @return array{totals: array{entitled: float, paid: float, outstanding: float}, rows: Collection<int, array<string, mixed>>}
     */
    public function dividends(ShareHolder $holder): array
    {
        $allocations = DividendAllocation::where('company_id', $holder->company_id)->where('share_holder_id', $holder->id)
            ->with(['declaration', 'payments' => fn ($query) => $query->orderBy('paid_at')->orderBy('id')])
            ->orderByDesc('id')->get();

        return [
            'totals' => $this->dividendTotals($holder),
            'rows' => $allocations->map(function (DividendAllocation $allocation): array {
                $paid = round((float) $allocation->payments->where('status', DividendPayment::STATUS_POSTED)->sum('amount'), 2);

                return [
                    'id' => $allocation->id,
                    'declaration_id' => $allocation->dividend_declaration_id,
                    'period' => $allocation->declaration?->period?->toDateString(),
                    'period_label' => $allocation->declaration?->periodLabel(),
                    'declared_at' => $allocation->declaration?->declared_at?->toDateTimeString(),
                    'shares_held' => $allocation->shares_held,
                    'total_shares' => $allocation->total_shares,
                    'share_percent' => (float) $allocation->share_percent,
                    'entitled' => (float) $allocation->amount,
                    'paid' => $paid,
                    'outstanding' => round(max(0, (float) $allocation->amount - $paid), 2),
                    'status' => DividendAllocation::STATUS_LABELS[$allocation->status] ?? strtoupper((string) $allocation->status),
                    'payments' => $allocation->payments->map(fn (DividendPayment $payment): array => [
                        'id' => $payment->id,
                        'amount' => (float) $payment->amount,
                        'pay_method' => $payment->pay_method,
                        'reference' => $payment->reference,
                        'paid_at' => $payment->paid_at?->toDateTimeString(),
                        'status' => $payment->status,
                        'reversed_at' => $payment->reversed_at?->toDateTimeString(),
                    ])->values(),
                ];
            })->values(),
        ];
    }

    /**
     * @return array{entitled: float, paid: float, outstanding: float}
     */
    public function dividendTotals(ShareHolder $holder): array
    {
        $entitled = round((float) DividendAllocation::where('company_id', $holder->company_id)->where('share_holder_id', $holder->id)->sum('amount'), 2);
        $paid = round((float) DividendPayment::where('company_id', $holder->company_id)->where('share_holder_id', $holder->id)->where('status', DividendPayment::STATUS_POSTED)->sum('amount'), 2);

        return ['entitled' => $entitled, 'paid' => $paid, 'outstanding' => round(max(0, $entitled - $paid), 2)];
    }

    /**
     * My statement for a date range: capital contributions posted (and reversals) with a running capital balance, dividend
     * payments and share movements; opening / closing capital, shares and dividends paid.
     *
     * @return array<string, mixed>
     */
    public function statement(ShareHolder $holder, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->startOfDay();
        $end = $to->endOfDay();
        $capitals = $holder->capitals()->where('status', Capital::STATUS_POSTED)->with('journalEntry')->get();
        $postedAt = fn (Capital $capital): CarbonImmutable => CarbonImmutable::parse($capital->contributed_at ?? $capital->created_at);

        $opening = round((float) $capitals->filter(fn (Capital $capital): bool => $postedAt($capital)->lt($start))->sum('amount')
            - (float) $capitals->filter(fn (Capital $capital): bool => $capital->reversed_at !== null && CarbonImmutable::parse($capital->reversed_at)->lt($start))->sum('amount'), 2);

        $lines = collect();
        foreach ($capitals as $capital) {
            if ($postedAt($capital)->between($start, $end)) {
                $lines->push(['date' => $postedAt($capital)->format('Y-m-d H:i:s'), 'type' => 'capital', 'description' => "Capital contribution ({$capital->pay_method})", 'reference' => $capital->journalEntry?->reference ?? $capital->receipt_number, 'capital_in' => (float) $capital->amount, 'capital_out' => 0.0, 'dividend_paid' => 0.0, 'shares' => 0]);
            }
            if ($capital->reversed_at !== null && CarbonImmutable::parse($capital->reversed_at)->between($start, $end)) {
                $lines->push(['date' => CarbonImmutable::parse($capital->reversed_at)->format('Y-m-d H:i:s'), 'type' => 'capital_reversal', 'description' => 'Capital contribution reversed', 'reference' => $capital->receipt_number, 'capital_in' => 0.0, 'capital_out' => (float) $capital->amount, 'dividend_paid' => 0.0, 'shares' => 0]);
            }
        }

        $payments = DividendPayment::where('company_id', $holder->company_id)->where('share_holder_id', $holder->id)->with('allocation.declaration')->get();
        foreach ($payments as $payment) {
            if ($payment->paid_at !== null && CarbonImmutable::parse($payment->paid_at)->between($start, $end)) {
                $lines->push(['date' => $payment->paid_at->format('Y-m-d H:i:s'), 'type' => 'dividend', 'description' => 'Dividend paid'.($payment->allocation?->declaration ? ' — '.$payment->allocation->declaration->periodLabel() : ''), 'reference' => $payment->reference, 'capital_in' => 0.0, 'capital_out' => 0.0, 'dividend_paid' => (float) $payment->amount, 'shares' => 0]);
            }
            if ($payment->reversed_at !== null && CarbonImmutable::parse($payment->reversed_at)->between($start, $end)) {
                $lines->push(['date' => $payment->reversed_at->format('Y-m-d H:i:s'), 'type' => 'dividend_reversal', 'description' => 'Dividend payment reversed', 'reference' => $payment->reference, 'capital_in' => 0.0, 'capital_out' => 0.0, 'dividend_paid' => -(float) $payment->amount, 'shares' => 0]);
            }
        }

        $movements = ShareTransaction::where('company_id', $holder->company_id)
            ->where(fn ($query) => $query->where('from_share_holder_id', $holder->id)->orWhere('to_share_holder_id', $holder->id))
            ->whereBetween('transacted_at', [$start, $end])->with(['fromShareHolder', 'toShareHolder'])->get();
        foreach ($movements as $movement) {
            $row = $this->presentShareTransaction($movement, $holder);
            $lines->push(['date' => $row['transacted_at'], 'type' => 'shares', 'description' => $row['type_label'].' ('.$row['counterparty'].')', 'reference' => $row['reference'], 'capital_in' => 0.0, 'capital_out' => 0.0, 'dividend_paid' => 0.0, 'shares' => $row['signed_shares']]);
        }

        $balance = $opening;
        $lines = $lines->sortBy('date')->values()->map(function (array $line) use (&$balance): array {
            $balance = round($balance + $line['capital_in'] - $line['capital_out'], 2);

            return $line + ['capital_balance' => $balance];
        });

        $openingHolding = $this->register->holdingAt($holder, $start->subDay());
        $closingHolding = $this->register->holdingAt($holder, $end);

        return [
            'share_holder' => ['holder_number' => $holder->holder_number, 'name' => $holder->full_name],
            'company' => Company::whereKey($holder->company_id)->value('name'),
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'generated_at' => now()->toDateTimeString(),
            'opening_capital' => $opening,
            'closing_capital' => $balance,
            'capital_in' => round((float) $lines->sum('capital_in'), 2),
            'capital_out' => round((float) $lines->sum('capital_out'), 2),
            'dividends_paid' => round((float) $lines->sum('dividend_paid'), 2),
            'opening_shares' => $openingHolding['shares'],
            'closing_shares' => $closingHolding['shares'],
            'closing_ownership_percent' => $closingHolding['ownership_percent'],
            'lines' => $lines->all(),
        ];
    }

    /**
     * Every shareholder's PUBLIC holding: number, name, shares, ownership %, capital contributed (posted). Nothing else
     * (no phone, e-mail, date of birth, photo, identity, bank details, documents or transactions) is ever included.
     *
     * @return array{totals: array{shareholders: int, total_shares: int, total_capital: float}, rows: list<array{holder_number: string, name: string, shares: int, ownership_percent: float, capital_contributed: float, is_me: bool}>, distribution: list<array{name: string, shares: int, ownership_percent: float}>}
     */
    public function directory(int $companyId, ?int $viewerShareHolderId = null): array
    {
        $summary = $this->ownership->summary($companyId);
        $rows = $summary->map(fn (array $row): array => [
            'holder_number' => $row['share_holder']->holder_number,
            'name' => $row['share_holder']->full_name,
            'shares' => $row['shares'],
            'ownership_percent' => $row['ownership_percent'],
            'capital_contributed' => $row['total_contributed'],
            'is_me' => $viewerShareHolderId !== null && $row['share_holder']->id === $viewerShareHolderId,
        ])->values();

        return [
            'totals' => [
                'shareholders' => $rows->count(),
                'total_shares' => (int) $rows->sum('shares'),
                'total_capital' => $this->ownership->totalContributed($companyId),
            ],
            'rows' => $rows->all(),
            'distribution' => $rows->where('shares', '>', 0)->sortByDesc('shares')->values()
                ->map(fn (array $row): array => ['name' => $row['name'], 'shares' => $row['shares'], 'ownership_percent' => $row['ownership_percent']])->all(),
        ];
    }

    /**
     * The company share structure (read-only).
     *
     * @return array<string, mixed>
     */
    public function structure(int $companyId): array
    {
        $structure = $this->register->structure($companyId);
        $issued = $this->register->issuedShares($companyId);
        $value = $this->register->currentValue($companyId);

        return [
            'has_structure' => $structure !== null,
            'authorised_shares' => $structure?->authorised_shares,
            'issued_shares' => $issued,
            'available_shares' => $structure === null ? null : $this->register->availableShares($structure),
            'par_value' => $structure === null ? null : (float) $structure->initial_share_value,
            'initial_shares' => $structure?->initial_shares,
            'established_on' => $structure?->established_on?->toDateString(),
            'current_share_value' => $value,
            'total_valuation' => round($issued * $value, 2),
            'shareholders' => ShareHolder::where('company_id', $companyId)->count(),
        ];
    }

    /**
     * Company bank accounts a contribution may be paid into — names only.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    public function bankAccounts(int $companyId): Collection
    {
        return BankAccount::where('company_id', $companyId)->orderBy('name')->get(['id', 'name'])
            ->map(fn (BankAccount $account): array => ['id' => $account->id, 'name' => $account->name])->values();
    }
}
