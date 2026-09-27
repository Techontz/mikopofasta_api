<?php

namespace App\Services\Approvals;

use App\Models\ApprovalPolicy;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Services\AccessControl;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Rule 6 — segregation of duties: the employee who initiated a financial transaction must not approve (post) it, even
 * when they hold the approval permission — Admin, Finance and Manager included. Another authorised user approves.
 * Exception (user decision 2026-09-16): the Super Admin may approve anything, including what they requested themselves.
 * Anyone else may self-approve only when the company EXPLICITLY grants `approvals.self_approve` (role permission or
 * employee override, {@see AccessControl::explicitlyGranted()}). Reversals keep the rule for everyone.
 *
 * C6 company approval policy: callers pass the workflow key ({@see ApprovalPolicy::WORKFLOWS}); the initiator may then
 * approve their own item only when BOTH the employee explicitly holds `approvals.self_approve` AND the company policy of that
 * workflow allows self-approval ({@see ApprovalPolicies}; no policy row = not allowed). Callers without a workflow key keep
 * the previous rule (the explicit permission alone). Reversals use the {@see ApprovalPolicy::REVERSALS} workflow.
 *
 * The same rule separates the stages of multi-stage workflows (a loan's manager, credit and finance approvals) and
 * reversals (the employee who posted a journal does not reverse it). Legacy rows without a recorded initiator are allowed.
 */
class SegregationOfDuties
{
    public const PERMISSION = 'approvals.self_approve';

    public const INITIATOR_MESSAGE = 'You initiated this transaction, so another authorised user must approve it.';

    public const REVERSER_MESSAGE = 'You posted this transaction, so another authorised user must reverse it.';

    public const STAGE_MESSAGE = 'You already handled an earlier stage of this transaction, so another authorised user must approve this stage.';

    public function __construct(
        private readonly AccessControl $access,
        private readonly ApprovalPolicies $policies,
    ) {}

    /**
     * Whether the employee may approve an item they initiated: the explicit permission, and — for a workflow key — the company
     * policy of that workflow allowing self-approval.
     */
    public function canSelfApprove(Employee $employee, ?string $workflow = null): bool
    {
        if (! $this->access->explicitlyGranted($employee, self::PERMISSION)) {
            return false;
        }

        return $workflow === null || $this->policies->allowsSelfApproval((int) $employee->company_id, $workflow);
    }

    /**
     * Why this employee may not approve a transaction initiated by the given employee(s), or null when they may.
     *
     * @param  int|list<int|null>|null  $initiatorEmployeeIds
     */
    public function blockedReason(int|array|null $initiatorEmployeeIds, Employee $approver, string $message = self::INITIATOR_MESSAGE, ?string $workflow = null): ?string
    {
        if (self::isSuperAdmin($approver)) {
            return null;
        }

        return $this->initiatorBlockedReason($initiatorEmployeeIds, $approver, $message, $workflow);
    }

    /**
     * The Super Admin approves anything, own requests included (never a Shareholder Portal login).
     */
    public static function isSuperAdmin(Employee $employee): bool
    {
        return $employee->role?->key === 'super_admin' && ! $employee->isShareholderAccount();
    }

    /**
     * @param  int|list<int|null>|null  $initiatorEmployeeIds
     */
    private function initiatorBlockedReason(int|array|null $initiatorEmployeeIds, Employee $approver, string $message, ?string $workflow): ?string
    {
        $ids = array_map('intval', array_filter(is_array($initiatorEmployeeIds) ? $initiatorEmployeeIds : [$initiatorEmployeeIds], fn ($id): bool => $id !== null));

        if (! in_array((int) $approver->id, $ids, true) || $this->canSelfApprove($approver, $workflow)) {
            return null;
        }

        return $message;
    }

    /**
     * Abort with 403 when the approver initiated the transaction (and holds no explicit self-approval permission).
     *
     * @param  int|list<int|null>|null  $initiatorEmployeeIds
     *
     * @throws AccessDeniedHttpException
     */
    public function assertCanApprove(int|array|null $initiatorEmployeeIds, Employee $approver, string $action = 'approve', string $message = self::INITIATOR_MESSAGE, ?string $workflow = null): void
    {
        $reason = $this->blockedReason($initiatorEmployeeIds, $approver, $message, $workflow);

        if ($reason !== null) {
            throw new AccessDeniedHttpException($reason);
        }
    }

    /**
     * Why this employee may not reverse the journal entry (they posted it), or null when they may.
     */
    public function reverseBlockedReason(?JournalEntry $entry, Employee $reverser): ?string
    {
        return $this->initiatorBlockedReason($entry?->employee_id, $reverser, self::REVERSER_MESSAGE, ApprovalPolicy::REVERSALS);
    }

    /**
     * Abort with 403 when the reverser recorded the transaction. Reversals keep rule 6 for everyone, the Super Admin included;
     * use it where the poster is a record's employee instead of a journal entry.
     *
     * @param  int|list<int|null>|null  $recordedByEmployeeIds
     *
     * @throws AccessDeniedHttpException
     */
    public function assertCanReverseRecord(int|array|null $recordedByEmployeeIds, Employee $reverser): void
    {
        $reason = $this->initiatorBlockedReason($recordedByEmployeeIds, $reverser, self::REVERSER_MESSAGE, ApprovalPolicy::REVERSALS);

        if ($reason !== null) {
            throw new AccessDeniedHttpException($reason);
        }
    }

    /**
     * @throws AccessDeniedHttpException
     */
    public function assertCanReverse(?JournalEntry $entry, Employee $reverser): void
    {
        $reason = $this->reverseBlockedReason($entry, $reverser);

        if ($reason !== null) {
            throw new AccessDeniedHttpException($reason);
        }
    }

    /**
     * List flags for a pending row: whether the viewer may approve it now and, when a permitted viewer cannot, why.
     *
     * @param  int|list<int|null>|null  $initiatorEmployeeIds
     * @return array{can_approve: bool, approve_blocked_reason: string|null}
     */
    public function flags(int|array|null $initiatorEmployeeIds, ?Employee $viewer, bool $pending, bool $permitted, string $message = self::INITIATOR_MESSAGE, ?string $workflow = null): array
    {
        if (! $pending || ! $permitted || $viewer === null) {
            return ['can_approve' => false, 'approve_blocked_reason' => null];
        }

        $reason = $this->blockedReason($initiatorEmployeeIds, $viewer, $message, $workflow);

        return ['can_approve' => $reason === null, 'approve_blocked_reason' => $reason];
    }
}
