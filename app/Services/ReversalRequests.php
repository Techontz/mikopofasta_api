<?php

namespace App\Services;

use App\Models\ApprovalPolicy;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanTransaction;
use App\Models\PenaltyPayment;
use App\Models\ReversalRequest;
use App\Models\SalaryAdvancePayment;
use App\Services\Approvals\SegregationOfDuties;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Maker/checker for money reversals (user ruling 2026-09-17). Finance REQUESTS the reversal of a loan repayment, a loan
 * disbursement, a direct penalty payment or a salary advance deposit; nothing is posted and nothing changes on the loan. Another user holding
 * `reversals.approve` (Finance, Admin, Super Admin) APPROVES it, which runs the existing dependency-checked reversal
 * ({@see LoanService::reverseRepayment()}, {@see LoanService::reverseDisbursement()}, {@see LoanService::reversePenaltyPayment()})
 * as the approver, or REJECTS it with a reason.
 *
 *  - Rule 6: the approver must not be the requester ({@see ApprovalPolicy::REVERSAL_REQUESTS}; the Super Admin may approve
 *    anything). The employee who POSTED the original transaction may neither request nor approve its reversal (Super Admin
 *    included), exactly as before.
 *  - One pending request per transaction; the business blockers are checked when requesting and again when approving.
 */
class ReversalRequests
{
    public const PENDING_MESSAGE = 'A reversal request for this transaction is already waiting for approval.';

    public function __construct(
        private readonly LoanService $loans,
        private readonly SegregationOfDuties $duties,
        private readonly SalaryAdvanceService $salaryAdvances,
    ) {}

    /**
     * Request the reversal of a loan repayment.
     *
     * @throws ValidationException
     */
    public function requestRepayment(LoanTransaction $deposit, string $reason, Employee $requester): ReversalRequest
    {
        return DB::transaction(function () use ($deposit, $reason, $requester): ReversalRequest {
            $loan = Loan::whereKey($deposit->loan_id)->lockForUpdate()->firstOrFail();
            $deposit = LoanTransaction::whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            $deposit->setRelation('loan', $loan);

            $this->guard($this->loans->repaymentReversalBlocker($deposit), $deposit);
            $this->duties->assertCanReverse($this->loans->repaymentJournalEntry($deposit), $requester);

            return $this->create(ReversalRequest::REPAYMENT, $deposit, $loan, (float) $deposit->amount, $reason, $requester);
        });
    }

    /**
     * Request the reversal of a loan disbursement.
     *
     * @throws ValidationException
     */
    public function requestDisbursement(Loan $loan, string $reason, Employee $requester): ReversalRequest
    {
        return DB::transaction(function () use ($loan, $reason, $requester): ReversalRequest {
            $loan = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();

            $this->guard($this->loans->disbursementReversalBlocker($loan), $loan);
            $this->duties->assertCanReverse($this->loans->disbursementJournalEntry($loan), $requester);

            return $this->create(ReversalRequest::DISBURSEMENT, $loan, $loan, (float) $loan->amount_approved, $reason, $requester);
        });
    }

    /**
     * Request the reversal of a direct penalty payment.
     *
     * @throws ValidationException
     */
    public function requestPenaltyPayment(PenaltyPayment $payment, string $reason, Employee $requester): ReversalRequest
    {
        return DB::transaction(function () use ($payment, $reason, $requester): ReversalRequest {
            $payment = PenaltyPayment::whereKey($payment->id)->lockForUpdate()->with('penalty.loan', 'journalEntry')->firstOrFail();

            $this->guard($this->loans->penaltyPaymentReversalBlocker($payment), $payment);
            $this->duties->assertCanReverse($payment->journalEntry, $requester);

            return $this->create(ReversalRequest::PENALTY_PAYMENT, $payment, $payment->penalty?->loan, (float) $payment->amount, $reason, $requester, $payment->penalty?->branch_id, (int) $payment->penalty?->company_id);
        });
    }

    /**
     * Request the reversal of a salary advance deposit.
     *
     * @throws ValidationException
     */
    public function requestSalaryAdvancePayment(SalaryAdvancePayment $payment, string $reason, Employee $requester): ReversalRequest
    {
        return DB::transaction(function () use ($payment, $reason, $requester): ReversalRequest {
            $payment = SalaryAdvancePayment::whereKey($payment->id)->lockForUpdate()->with('salaryAdvance', 'journalEntry')->firstOrFail();

            $this->guard($this->salaryAdvances->paymentReversalBlocker($payment), $payment);
            $this->duties->assertCanReverse($payment->journalEntry, $requester);

            return $this->create(ReversalRequest::SALARY_ADVANCE_PAYMENT, $payment, null, (float) $payment->amount, $reason, $requester, $payment->salaryAdvance->branch_id, (int) $payment->salaryAdvance->company_id);
        });
    }

    /**
     * Approve a pending request: the reversal is posted now, as the approver, with every dependency re-checked.
     *
     * @return array{request: ReversalRequest, result: mixed}
     *
     * @throws ValidationException
     * @throws AccessDeniedHttpException
     */
    public function approve(ReversalRequest $request, Employee $approver): array
    {
        return DB::transaction(function () use ($request, $approver): array {
            $locked = ReversalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReversalRequest::PENDING) {
                throw ValidationException::withMessages(['request' => 'This reversal request has already been processed.']);
            }
            $this->duties->assertCanApprove($locked->requested_by, $approver, 'reversal', workflow: ApprovalPolicy::REVERSAL_REQUESTS);

            $subject = $locked->subject;
            if ($subject === null) {
                throw ValidationException::withMessages(['request' => 'The transaction of this request no longer exists.']);
            }

            $result = match ($locked->type) {
                ReversalRequest::REPAYMENT => $this->loans->reverseRepayment($subject, $locked->reason, $approver),
                ReversalRequest::DISBURSEMENT => $this->loans->reverseDisbursement($subject, $locked->reason, $approver),
                ReversalRequest::PENALTY_PAYMENT => $this->loans->reversePenaltyPayment($subject, $locked->reason, $approver),
                ReversalRequest::SALARY_ADVANCE_PAYMENT => $this->salaryAdvances->reversePayment($subject, $locked->reason, $approver),
            };
            $reversalEntryId = match ($locked->type) {
                ReversalRequest::REPAYMENT => $result['reversal']->id,
                ReversalRequest::DISBURSEMENT => LoanDisbursement::where('loan_id', $subject->id)->whereNotNull('reversal_journal_entry_id')->latest('id')->value('reversal_journal_entry_id'),
                ReversalRequest::PENALTY_PAYMENT, ReversalRequest::SALARY_ADVANCE_PAYMENT => $result->reversal_journal_entry_id,
            };

            $locked->update(['status' => ReversalRequest::APPROVED, 'approved_by' => $approver->id, 'approved_at' => now(), 'reversal_journal_entry_id' => $reversalEntryId]);

            return ['request' => $locked, 'result' => $result];
        });
    }

    /**
     * Reject a pending request: nothing was posted; the row keeps who rejected it, when and why. The requester may withdraw
     * their own request this way.
     *
     * @throws ValidationException
     */
    public function reject(ReversalRequest $request, string $reason, Employee $employee): ReversalRequest
    {
        return DB::transaction(function () use ($request, $reason, $employee): ReversalRequest {
            $locked = ReversalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReversalRequest::PENDING) {
                throw ValidationException::withMessages(['reason' => 'Only pending reversal requests can be rejected.']);
            }

            $locked->update(['status' => ReversalRequest::REJECTED, 'rejected_by' => $employee->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            if ($locked->loan !== null) {
                app(LoanWorkflow::class)->record($locked->loan, 'REVERSAL_REJECTED', $locked->loan->status, $employee, [
                    'reversal_request_id' => $locked->id,
                    'type' => $locked->type,
                    'amount' => (float) $locked->amount,
                    'reason' => $reason,
                ]);
            }

            return $locked;
        });
    }

    /**
     * The pending request of a transaction, if any.
     */
    public function pendingFor(Model $subject): ?ReversalRequest
    {
        return ReversalRequest::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('status', ReversalRequest::PENDING)
            ->with('requester')
            ->latest('id')
            ->first();
    }

    /**
     * API row of a request with the viewer's approval flags.
     *
     * @return array<string, mixed>
     */
    public function present(ReversalRequest $row, ?Employee $viewer, bool $mayApprove): array
    {
        $pending = $row->status === ReversalRequest::PENDING;

        return [
            'id' => $row->id,
            'type' => $row->type,
            'type_label' => $row->typeLabel(),
            'status' => $row->status,
            'amount' => (float) $row->amount,
            'reason' => $row->reason,
            'loan_id' => $row->loan_id,
            'loan_number' => $row->loan?->loan_number,
            'customer' => $row->loan?->customer?->full_name ?? match (true) {
                $row->subject instanceof PenaltyPayment => $row->subject->penalty?->customer?->full_name,
                $row->subject instanceof SalaryAdvancePayment => $row->subject->salaryAdvance?->customer?->full_name,
                default => null,
            },
            'branch' => $row->branch?->name,
            'description' => $this->describe($row),
            'requested_by' => $row->requester?->full_name,
            'requested_at' => $row->created_at?->toDateTimeString(),
            'approved_by' => $row->approver?->full_name,
            'approved_at' => $row->approved_at?->toDateTimeString(),
            'rejected_by' => $row->rejecter?->full_name,
            'rejected_at' => $row->rejected_at?->toDateTimeString(),
            'rejection_reason' => $row->rejection_reason,
            'reversal_reference' => $row->reversalJournalEntry?->reference,
            'effect' => $this->effect($row),
            // The requester may withdraw (reject) their own pending request; approvers may reject any.
            'can_reject' => $pending && $viewer !== null && ($mayApprove || (int) $row->requested_by === (int) $viewer->id),
            ...$this->duties->flags($row->requested_by, $viewer, $pending, $mayApprove, workflow: ApprovalPolicy::REVERSAL_REQUESTS),
        ];
    }

    /**
     * One line saying which transaction the request reverses.
     */
    public function describe(ReversalRequest $row): string
    {
        $subject = $row->subject;

        return match (true) {
            $subject instanceof LoanTransaction => 'Repayment of '.money($subject->amount).' on '.$subject->transaction_date?->toDateString().' — loan '.$row->loan?->loan_number,
            $subject instanceof Loan && $subject->topup_of_loan_id !== null => 'Top-up disbursement of '.money($subject->amount_approved).' — loan '.$subject->loan_number
                .' (settled loan '.($subject->topupOf?->loan_number ?? '—').')',
            $subject instanceof Loan => 'Disbursement of '.money($subject->amount_approved).' — loan '.$subject->loan_number,
            $subject instanceof PenaltyPayment => 'Penalty payment of '.money($subject->amount).' on '.$subject->paid_on?->toDateString().($row->loan ? ' — loan '.$row->loan->loan_number : ''),
            $subject instanceof SalaryAdvancePayment => 'Salary advance deposit of '.money($subject->amount).' on '.$subject->created_at?->format('Y-m-d H:i'),
            default => $row->typeLabel(),
        };
    }

    /**
     * What approving the request posts, in one sentence, for the approver.
     */
    public function effect(ReversalRequest $row): string
    {
        $subject = $row->subject;

        return match (true) {
            $subject instanceof LoanTransaction => 'Principal, penalty, interest and insurance of the repayment are mirrored out exactly; the money returns to SUSPENSE (unallocated) for re-allocation or refund and a loan closed by it reopens.',
            $subject instanceof Loan && $subject->topup_of_loan_id !== null => 'The disbursement is mirrored back to the PRINCIPAL A/C and the loan is cancelled; the settlement it made on loan '
                .($subject->topupOf?->loan_number ?? '—').' is reversed too, so that loan reopens with its balance owed again.',
            $subject instanceof Loan => 'The disbursement is mirrored back to the PRINCIPAL A/C (a deducted fee out of FEE INCOME) and the loan is cancelled.',
            $subject instanceof PenaltyPayment => 'The penalty payment is mirrored out of PENALTY INCOME and the amount is owed on the penalty again.',
            $subject instanceof SalaryAdvancePayment => 'The deposit is mirrored out of the ledger (principal back to the receivable, profit out of SALARY ADVANCE INCOME) and the amount is owed on the salary advance again; a finished advance becomes active again.',
            default => '',
        };
    }

    /**
     * @throws ValidationException
     */
    private function guard(?string $blocker, Model $subject): void
    {
        if ($blocker !== null) {
            throw ValidationException::withMessages(['reason' => $blocker]);
        }
        if ($this->pendingFor($subject) !== null) {
            throw ValidationException::withMessages(['reason' => self::PENDING_MESSAGE]);
        }
    }

    private function create(string $type, Model $subject, ?Loan $loan, float $amount, string $reason, Employee $requester, ?int $branchId = null, ?int $companyId = null): ReversalRequest
    {
        $request = ReversalRequest::create([
            'company_id' => $companyId ?? $loan?->company_id ?? $requester->company_id,
            'branch_id' => $branchId ?? $loan?->branch_id,
            'loan_id' => $loan?->id,
            'type' => $type,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'amount' => round($amount, 2),
            'reason' => $reason,
            'status' => ReversalRequest::PENDING,
            'requested_by' => $requester->id,
        ]);

        if ($loan !== null) {
            app(LoanWorkflow::class)->record($loan, 'REVERSAL_REQUESTED', $loan->status, $requester, [
                'reversal_request_id' => $request->id,
                'type' => $type,
                'amount' => round($amount, 2),
                'reason' => $reason,
            ]);
        }

        return $request;
    }
}
