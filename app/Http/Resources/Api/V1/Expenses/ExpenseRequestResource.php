<?php

namespace App\Http\Resources\Api\V1\Expenses;

use App\Enums\Account;
use App\Models\ApprovalPolicy;
use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\ExpenseApproval;
use App\Services\TransferReversal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin ExpenseRequest
 */
class ExpenseRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $approval = app(ExpenseApproval::class);
        $required = $approval->requiredPermissions($this->resource, (float) $this->amount);
        $mayApprove = collect($required)->contains(fn (string $permission): bool => Gate::allows($permission));
        $viewer = $request->user() instanceof Employee ? $request->user() : null;
        $duties = app(SegregationOfDuties::class);
        $reverseBlocked = match (true) {
            $this->status !== 'accepted', ! $mayApprove || ! Gate::allows('accounting.reverse') => null,
            default => $approval->reverseBlockedReason($this->resource)
                ?? ($viewer === null ? null : $duties->reverseBlockedReason(app(TransferReversal::class)->postedEntry($this->resource), $viewer)),
        };
        $approvalFlags = $duties->flags($this->employee_id, $viewer, $this->status === 'pending', $mayApprove, workflow: ApprovalPolicy::EXPENSES);

        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'branch_id' => $this->branch_id,
            'branch' => $this->branch?->name,
            'expense_type_id' => $this->expense_type_id,
            'expense' => $this->expenseType?->name,
            'bank_account_id' => $this->bank_account_id,
            'bank_account' => $this->bankAccount?->name,
            'amount' => (float) $this->amount,
            'description' => $this->description,
            'comment' => $this->comment,
            'status' => $this->status,
            'staff' => $this->employee?->full_name,
            'request_date' => $this->request_date?->toDateString(),
            'paid_from_account' => $this->paid_from_account,
            'paid_from' => $this->paid_from_account ? Account::tryFrom($this->paid_from_account)?->label() : null,
            'approved_by' => $this->approver?->full_name,
            'approved_at' => $this->approved_at?->toDateString(),
            'approval_level' => in_array('expenses.approve_branch', $required, true) ? 'finance' : 'admin',
            ...$approvalFlags,
            'journal_reference' => $this->whenLoaded('journalEntry', fn () => $this->journalEntry?->reference),
            'reversed_at' => $this->reversed_at?->toDateTimeString(),
            'reversed_by' => $this->whenLoaded('reversedBy', fn () => $this->reversedBy?->full_name),
            'reversal_reason' => $this->reversal_reason,
            'reversal_reference' => $this->whenLoaded('reversalJournalEntry', fn () => $this->reversalJournalEntry?->reference),
            'can_reverse' => $this->status === 'accepted' && $mayApprove && Gate::allows('accounting.reverse') && $reverseBlocked === null,
            'reverse_blocked_reason' => $reverseBlocked,
        ];
    }
}
