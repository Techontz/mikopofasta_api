<?php

namespace App\Http\Controllers\Api\V1\Shares;

use App\Enums\ShareTransactionType;
use App\Models\BankAccount;
use App\Models\Capital;
use App\Models\ShareTransaction;
use App\Services\Shares\ShareRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * {value,label} dropdown lists used by the Shares pages.
 */
class ShareOptionController extends SharesController
{
    public function __construct(private readonly ShareRegister $register) {}

    /**
     * Registered shareholders with their current shares in the label.
     */
    public function shareHolders(Request $request): JsonResponse
    {
        $this->authorizeAny('shares.view', 'shares.issue', 'shares.transfer', 'shares.manage');

        $rows = $this->register->register($this->companyId())
            ->when($request->boolean('holding'), fn ($rows) => $rows->where('shares', '>', 0));

        return response()->json(['data' => $rows->map(fn (array $row): array => [
            'value' => (string) $row['share_holder']->id,
            'label' => $row['share_holder']->full_name.' — '.number_format($row['shares']).' shares',
            'shares' => $row['shares'],
        ])->values()]);
    }

    /**
     * Recorded capital contributions of a shareholder that no active share transaction is linked to yet.
     */
    public function contributions(Request $request): JsonResponse
    {
        $this->authorizeAny('shares.issue', 'shares.manage');
        $request->validate(['share_holder_id' => ['required', 'integer']]);

        $linked = ShareTransaction::where('company_id', $this->companyId())->where('status', ShareTransaction::COMPLETED)->whereNotNull('capital_id')->pluck('capital_id');

        return response()->json(['data' => Capital::where('company_id', $this->companyId())
            ->where('share_holder_id', $request->integer('share_holder_id'))
            ->active()
            ->whereNotIn('id', $linked)
            ->with(['journalEntry', 'asset'])
            ->orderBy('id')
            ->get()
            ->map(fn (Capital $capital): array => [
                'value' => (string) $capital->id,
                'label' => ($capital->asset ? 'ASSET '.$capital->asset->asset_code.' '.$capital->asset->name.' — ' : $capital->pay_method.' — ').number_format((float) $capital->amount).' — '.($capital->contributed_at ?? $capital->created_at)?->toDateString().' — '.($capital->journalEntry?->reference ?? 'no journal'),
                'amount' => (float) $capital->amount,
            ])]);
    }

    public function bankAccounts(): JsonResponse
    {
        $this->authorizeAny('shares.issue', 'shares.manage');

        return response()->json(['data' => BankAccount::where('company_id', $this->companyId())->orderBy('id')->get()
            ->map(fn (BankAccount $account): array => ['value' => (string) $account->id, 'label' => $account->name])]);
    }

    public function types(): JsonResponse
    {
        $this->authorizeAny('shares.view');

        return response()->json(['data' => ShareTransactionType::options()]);
    }
}
