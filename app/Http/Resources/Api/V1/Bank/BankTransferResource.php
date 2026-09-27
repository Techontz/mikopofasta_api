<?php

namespace App\Http\Resources\Api\V1\Bank;

use App\Enums\Account;
use App\Models\ApprovalPolicy;
use App\Models\BankTransfer;
use App\Models\Employee;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\CompanyFunds;
use App\Services\TransferReversal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin BankTransfer
 */
class BankTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user() instanceof Employee ? $request->user() : null;
        $canDecide = in_array($this->type, [CompanyFunds::RESERVE_TO_INVESTMENT, CompanyFunds::RESERVE_TO_PRINCIPAL], true)
            ? $viewer !== null && CompanyFunds::canDecideReserve($viewer)
            : Gate::allows('bank.manage');

        return [
            'id' => $this->id,
            'type' => $this->type,
            'branch_id' => $this->branch_id,
            'branch' => $this->branch?->name,
            'branch_account' => $this->branch_account,
            'branch_account_label' => $this->branch_account ? Account::tryFrom($this->branch_account)?->label() : null,
            'bank_account_id' => $this->bank_account_id,
            'bank_account' => $this->bankAccount?->name,
            'hq_account' => $this->hq_account,
            'hq_account_label' => $this->hq_account ? Account::tryFrom($this->hq_account)?->label() : null,
            'amount' => (float) $this->amount,
            'charge' => (float) $this->charge,
            'status' => $this->status,
            'transfer_date' => $this->transfer_date?->toDateString(),
            'reference' => $this->reference,
            'journal_reference' => $this->whenLoaded('journalEntry', fn () => $this->journalEntry?->reference),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'created_at' => $this->created_at?->toDateTimeString(),
            'reversed_at' => $this->reversed_at?->toDateTimeString(),
            'reversed_by' => $this->whenLoaded('reversedBy', fn () => $this->reversedBy?->full_name),
            'reversal_reason' => $this->reversal_reason,
            'reversal_reference' => $this->whenLoaded('reversalJournalEntry', fn () => $this->reversalJournalEntry?->reference),
            'initiated_by' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'approved_by' => $this->whenLoaded('approver', fn () => $this->approver?->full_name),
            'approved_at' => $this->approved_at?->toDateTimeString(),
            'rejected_by' => $this->whenLoaded('rejectedBy', fn () => $this->rejectedBy?->full_name),
            'rejected_at' => $this->rejected_at?->toDateTimeString(),
            'rejection_reason' => $this->rejection_reason,
            ...app(SegregationOfDuties::class)->flags($this->employee_id, $viewer, $this->status === 'pending', $canDecide, workflow: ApprovalPolicy::BANK_TRANSFERS),
            'can_reject' => $this->status === 'pending' && $canDecide,
            ...app(TransferReversal::class)->flags($this->resource, Gate::allows('bank.manage') && Gate::allows('accounting.reverse')),
        ];
    }
}
