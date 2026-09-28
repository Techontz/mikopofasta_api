<?php

namespace App\Http\Resources\Api\V1\Hq;

use App\Enums\Account;
use App\Enums\HqFund;
use App\Models\ApprovalPolicy;
use App\Models\Employee;
use App\Models\HqTransaction;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\ShareholderAccounts;
use App\Services\TransferReversal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin HqTransaction
 */
class HqTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_account' => $this->from_account,
            // An HQ fund row on the FROM side and a shareholders' account on the TO side; older rows name HQ ledger
            // accounts on both sides, so those labels are still resolved.
            'from_account_label' => HqFund::tryFrom($this->from_account)?->label() ?? Account::tryFrom($this->from_account)?->label(),
            'to_account' => $this->to_account,
            'to_bank_account_id' => $this->to_bank_account_id === null ? null : (int) $this->to_bank_account_id,
            'to_account_label' => $this->to_bank_account_id !== null
                ? $this->bankAccount?->name
                : ShareholderAccounts::nameOf($this->to_account) ?? Account::tryFrom($this->to_account)?->label(),
            'amount' => (float) $this->amount,
            'charge' => (float) $this->charge,
            'status' => $this->status,
            'staff' => $this->employee?->full_name,
            'date' => $this->created_at?->toDateString(),
            'approved_at' => $this->approved_at?->toDateString(),
            'journal_reference' => $this->whenLoaded('journalEntry', fn () => $this->journalEntry?->reference),
            'reversed_at' => $this->reversed_at?->toDateTimeString(),
            'reversed_by' => $this->whenLoaded('reversedBy', fn () => $this->reversedBy?->full_name),
            'reversal_reason' => $this->reversal_reason,
            'reversal_reference' => $this->whenLoaded('reversalJournalEntry', fn () => $this->reversalJournalEntry?->reference),
            ...app(SegregationOfDuties::class)->flags($this->employee_id, $request->user() instanceof Employee ? $request->user() : null, $this->status === 'pending', Gate::allows('hq.manage'), workflow: ApprovalPolicy::HQ_TRANSACTIONS),
            ...app(TransferReversal::class)->flags($this->resource, Gate::allows('hq.manage') && Gate::allows('accounting.reverse')),
        ];
    }
}
