<?php

namespace App\Services\Reports;

use App\Enums\ShareTransactionType;
use App\Models\ShareTransaction;
use App\Models\ShareValuation;
use App\Services\Shares\ShareRegister;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Reports → Shares: current ownership, ownership as of a date, ownership distribution, valuation history, share
 * transaction history, issuances and transfers. Every figure is read from the share register.
 */
class ShareReports
{
    /**
     * Relations every transaction row needs for presentation.
     *
     * @var list<string>
     */
    public const TRANSACTION_RELATIONS = ['fromShareHolder', 'toShareHolder', 'capital', 'journalEntry', 'performer', 'reversalOf', 'reversal'];

    public function __construct(private readonly ShareRegister $register) {}

    /**
     * Ownership of every shareholder (now, or as of the end of a date).
     *
     * @return array{as_of: string, total_shares: int, share_value: float, total_valuation: float, shareholders_with_shares: int, rows: Collection<int, array<string, mixed>>}
     */
    public function ownership(int $companyId, ?CarbonInterface $asOf = null): array
    {
        $rows = $this->register->register($companyId, $asOf);
        $total = (int) $rows->sum('shares');
        $value = $this->register->valueAt($companyId, $asOf) ?? 0.0;

        return [
            'as_of' => ($asOf ?? CarbonImmutable::today())->toDateString(),
            'total_shares' => $total,
            'share_value' => $value,
            'total_valuation' => $this->register->holdingValue($total, $value),
            'shareholders_with_shares' => $rows->where('shares', '>', 0)->count(),
            'rows' => $rows,
        ];
    }

    /**
     * Holders ranked by shares with cumulative ownership and concentration bands.
     *
     * @return array{total_shares: int, holders: int, largest_percent: float, top_three_percent: float, bands: list<array{band: string, holders: int, shares: int, percent: float}>, rows: Collection<int, array<string, mixed>>}
     */
    public function distribution(int $companyId): array
    {
        $rows = $this->register->register($companyId)->where('shares', '>', 0)->sortByDesc('shares')->values();
        $total = (int) $rows->sum('shares');
        $cumulative = 0;

        $ranked = $rows->map(function (array $row, int $index) use (&$cumulative, $total): array {
            $cumulative += $row['shares'];

            return $row + ['rank' => $index + 1, 'cumulative_percent' => $this->register->percentOf($cumulative, $total)];
        });

        $bands = [
            ['band' => '50% and above', 'min' => 50.0, 'max' => INF],
            ['band' => '20% to under 50%', 'min' => 20.0, 'max' => 50.0],
            ['band' => '5% to under 20%', 'min' => 5.0, 'max' => 20.0],
            ['band' => 'Under 5%', 'min' => 0.0, 'max' => 5.0],
        ];

        return [
            'total_shares' => $total,
            'holders' => $rows->count(),
            'largest_percent' => (float) ($ranked->first()['ownership_percent'] ?? 0),
            'top_three_percent' => $this->register->percentOf((int) $rows->take(3)->sum('shares'), $total),
            'bands' => array_map(function (array $band) use ($rows, $total): array {
                $inBand = $rows->filter(fn (array $row): bool => $row['ownership_percent'] >= $band['min'] && $row['ownership_percent'] < $band['max']);

                return ['band' => $band['band'], 'holders' => $inBand->count(), 'shares' => (int) $inBand->sum('shares'), 'percent' => $this->register->percentOf((int) $inBand->sum('shares'), $total)];
            }, $bands),
            'rows' => $ranked,
        ];
    }

    /**
     * Every valuation event, including reversed ones, newest first.
     *
     * @return EloquentCollection<int, ShareValuation>
     */
    public function valuations(int $companyId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): EloquentCollection
    {
        return ShareValuation::where('company_id', $companyId)
            ->when($from !== null, fn (Builder $query) => $query->whereDate('valuation_date', '>=', $from->toDateString()))
            ->when($to !== null, fn (Builder $query) => $query->whereDate('valuation_date', '<=', $to->toDateString()))
            ->with(['performer', 'reverser'])
            ->orderByDesc('valuation_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Share transactions filtered by type(s), shareholder (either side), status and date range; newest first.
     *
     * @param  list<ShareTransactionType>  $types
     * @return EloquentCollection<int, ShareTransaction>
     */
    public function transactions(int $companyId, array $types = [], ?int $shareHolderId = null, ?string $status = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $limit = null): EloquentCollection
    {
        return ShareTransaction::where('company_id', $companyId)
            ->when($types !== [], fn (Builder $query) => $query->whereIn('type', array_map(fn (ShareTransactionType $type): string => $type->value, $types)))
            ->when($shareHolderId !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->where('from_share_holder_id', $shareHolderId)->orWhere('to_share_holder_id', $shareHolderId)))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($from !== null, fn (Builder $query) => $query->where('transacted_at', '>=', CarbonImmutable::parse($from)->startOfDay()))
            ->when($to !== null, fn (Builder $query) => $query->where('transacted_at', '<=', CarbonImmutable::parse($to)->endOfDay()))
            ->with(self::TRANSACTION_RELATIONS)
            ->orderByDesc('transacted_at')
            ->orderByDesc('id')
            ->when($limit !== null, fn (Builder $query) => $query->limit($limit))
            ->get();
    }

    /**
     * Initial allocations and issuances with totals by payment treatment. Reversed rows are listed but excluded from totals.
     *
     * @return array{rows: EloquentCollection<int, ShareTransaction>, totals: array{shares: int, amount: float, paid_amount: float, linked_amount: float, non_cash_shares: int}}
     */
    public function issuances(int $companyId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $rows = $this->transactions($companyId, [ShareTransactionType::InitialAllocation, ShareTransactionType::Issuance, ShareTransactionType::BonusIssuance], null, null, $from, $to);
        $active = $rows->where('status', ShareTransaction::COMPLETED);

        return [
            'rows' => $rows,
            'totals' => [
                'shares' => (int) $active->sum('shares'),
                'amount' => round((float) $active->sum('total_amount'), 2),
                'paid_amount' => round((float) $active->where('payment_treatment', ShareTransaction::TREATMENT_PAID)->sum('total_amount'), 2),
                'linked_amount' => round((float) $active->where('payment_treatment', ShareTransaction::TREATMENT_LINKED)->sum('total_amount'), 2),
                'non_cash_shares' => (int) $active->where('payment_treatment', ShareTransaction::TREATMENT_NO_CASH)->sum('shares'),
            ],
        ];
    }

    /**
     * Transfers between shareholders with totals. Reversed rows are listed but excluded from totals.
     *
     * @return array{rows: EloquentCollection<int, ShareTransaction>, totals: array{shares: int, consideration: float}}
     */
    public function transfers(int $companyId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $rows = $this->transactions($companyId, [ShareTransactionType::Transfer], null, null, $from, $to);
        $active = $rows->where('status', ShareTransaction::COMPLETED);

        return [
            'rows' => $rows,
            'totals' => ['shares' => (int) $active->sum('shares'), 'consideration' => round((float) $active->sum('total_amount'), 2)],
        ];
    }
}
