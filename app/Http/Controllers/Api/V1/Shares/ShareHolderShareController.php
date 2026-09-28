<?php

namespace App\Http\Controllers\Api\V1\Shares;

use App\Http\Resources\Api\V1\Shares\ShareTransactionResource;
use App\Models\Capital;
use App\Models\ShareHolder;
use App\Services\Reports\ShareReports;
use App\Services\ShareholderOwnership;
use App\Services\Shares\ShareRegister;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shares → Shareholders, Share Register and the shareholder share profile. Shareholders are registered in
 * Capital → Shareholders; this module only reads them.
 */
class ShareHolderShareController extends SharesController
{
    public function __construct(
        private readonly ShareRegister $register,
        private readonly ShareReports $reports,
        private readonly ShareholderOwnership $ownership,
    ) {}

    /**
     * Every registered shareholder with share data (shareholders without shares included).
     */
    public function index(): JsonResponse
    {
        $this->authorizeAny('shares.view');

        $contributions = $this->contributionColumns();

        return response()->json(['data' => $this->register->register($this->companyId())
            ->map(fn (array $row): array => $this->presentHolder($row['share_holder']) + $this->presentRow($row) + $contributions($row['share_holder']->id))
            ->values()]);
    }

    /**
     * Share register now or `as_of` a date: Shareholder, Shares Owned, Ownership %, Current Share Value, Holding Value,
     * Date Acquired, Status.
     */
    public function register(Request $request): JsonResponse
    {
        $this->authorizeAny('shares.view');
        $request->validate(['as_of' => ['nullable', 'date', 'before_or_equal:today']]);

        $ownership = $this->reports->ownership($this->companyId(), $this->date($request, 'as_of'));
        $contributions = $this->contributionColumns();

        return response()->json(['data' => [
            'as_of' => $ownership['as_of'],
            'total_shares' => $ownership['total_shares'],
            'share_value' => $ownership['share_value'],
            'total_valuation' => $ownership['total_valuation'],
            'can_view_contributions' => $this->canSeeCapital(),
            'rows' => $ownership['rows']->map(fn (array $row): array => $this->presentRow($row) + $contributions($row['share_holder']->id))->values(),
        ]]);
    }

    /**
     * Share profile: personal information, current holding, holding value history and movement history; capital
     * contribution history for users who may see capital.
     */
    public function show(ShareHolder $shareHolder): JsonResponse
    {
        $this->authorizeAny('shares.view');
        $this->ensureCompany($shareHolder);

        $row = $this->register->register($this->companyId())->first(fn (array $row): bool => $row['share_holder']->id === $shareHolder->id);
        $canSeeCapital = $this->canSeeCapital();
        $contributions = $canSeeCapital ? $shareHolder->capitals()->with(['bankAccount', 'journalEntry', 'recorder', 'shareTransactions', 'asset'])->orderBy('id')->get() : null;
        $breakdown = $canSeeCapital ? $this->ownership->breakdownByShareHolder($this->companyId(), $shareHolder->id)->get($shareHolder->id) : null;

        return response()->json(['data' => [
            'share_holder' => $this->presentHolder($shareHolder),
            'holding' => $this->presentRow($row),
            'history' => $this->register->holdingHistory($shareHolder),
            'transactions' => ShareTransactionResource::collection($this->reports->transactions($this->companyId(), shareHolderId: $shareHolder->id)),
            'can_view_contributions' => $canSeeCapital,
            'total_contributed' => $contributions === null ? null : round((float) $contributions->whereNull('reversed_at')->sum('amount'), 2),
            'contribution_breakdown' => $canSeeCapital ? ($breakdown ?? ['cash' => 0.0, 'bank' => 0.0, 'asset' => 0.0, 'total' => 0.0]) : null,
            'contributions' => $contributions?->map(fn (Capital $capital): array => [
                'id' => $capital->id,
                'amount' => (float) $capital->amount,
                'pay_method' => $capital->pay_method,
                'receiving_account_label' => $capital->receivingAccountLabel(),
                'receipt_number' => $capital->receipt_number,
                'contributed_at' => ($capital->contributed_at ?? $capital->created_at)?->format('Y-m-d H:i:s'),
                'recorded_by' => $capital->recorder?->full_name,
                'journal_reference' => $capital->journalEntry?->reference,
                'share_transaction_reference' => $capital->shareTransactions->firstWhere('status', 'completed')?->reference,
                'asset_id' => $capital->asset?->id,
                'asset_code' => $capital->asset?->asset_code,
                'asset_name' => $capital->asset?->name,
                'reversed' => $capital->isReversed(),
            ])->values(),
        ]]);
    }

    /**
     * Contribution columns (cash / bank / asset / total) per shareholder for users who may see capital; empty otherwise.
     *
     * @return Closure(int): array<string, float|null>
     */
    private function contributionColumns(): Closure
    {
        if (! $this->canSeeCapital()) {
            return fn (int $id): array => [];
        }

        $breakdown = $this->ownership->breakdownByShareHolder($this->companyId());

        return fn (int $id): array => [
            'total_contributed' => $breakdown[$id]['total'] ?? 0.0,
            'cash_contributed' => $breakdown[$id]['cash'] ?? 0.0,
            'bank_contributed' => $breakdown[$id]['bank'] ?? 0.0,
            'asset_contributed' => $breakdown[$id]['asset'] ?? 0.0,
        ];
    }

    /**
     * Passport photo for users who may see the share register.
     */
    public function photo(ShareHolder $shareHolder): StreamedResponse
    {
        $this->authorizeAny('shares.view', 'capital.view', 'capital.manage');
        $this->ensureCompany($shareHolder);

        abort_unless($shareHolder->passport_photo && Storage::disk(ShareHolder::DISK)->exists($shareHolder->passport_photo), 404);

        return Storage::disk(ShareHolder::DISK)->response($shareHolder->passport_photo, null, ['Cache-Control' => 'private, max-age=300']);
    }
}
