<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Enums\LoanStatus;
use App\Enums\PaymentStatus;
use App\Http\Requests\Api\Loans\RecordRecoveryRequest;
use App\Http\Requests\Api\Loans\ReverseRepaymentRequest;
use App\Models\Loan;
use App\Models\LoanRecovery;
use App\Services\LoanRecoveryService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Recoveries on written-off loans (C3 Option B, {@see LoanRecoveryService}): split Principal → Penalty → Interest → Insurance
 * without reopening the loan or touching its write-off. Only confirmed money is recovered: a Finance user (payments.suspense)
 * records it in one step (Dr BANK / Cr SUSPENSE → Dr SUSPENSE / Cr BANK → recovery journal); anyone else's entry is branch money
 * held pending Finance (cash: teller cash receipt; bank / mobile: pending approval) with no income until Finance confirms it.
 * Loans outside the employee's company / branch scope are not found; reversals need loans.reverse_repayment and a reason.
 * Duplicate submissions are covered by the Idempotency-Key middleware and the unique (channel, transaction_id) of payments.
 */
class LoanRecoveryController extends LoanApiController
{
    public function __construct(
        private readonly LoanRecoveryService $recoveries,
        private readonly PaymentService $payments,
    ) {}

    /**
     * GET /loans/{loan}/recoveries
     */
    public function index(Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.view');
        $this->ensureVisible($loan);

        $viewer = $this->currentEmployee();
        $rows = $loan->recoveries()->with(['employee', 'reverser', 'journalEntry', 'reversalJournalEntry', 'payment'])->latest('id')->get();

        return response()->json([
            'data' => $rows->map(fn (LoanRecovery $recovery): array => $this->recoveries->present($recovery, $viewer))->values(),
            'recovery' => $this->recoveries->position($loan),
        ]);
    }

    /**
     * POST /loans/{loan}/recoveries
     */
    public function store(RecordRecoveryRequest $request, Loan $loan): JsonResponse
    {
        $this->ensureVisible($loan);
        $employee = $this->currentEmployee();
        $method = $request->string('method')->toString();
        $data = [
            'amount' => (float) $request->input('amount'),
            'channel' => $method,
            'reference' => $request->input('reference'),
            'transaction_id' => $request->input('transaction_id'),
            'bank_account_id' => $request->input('bank_account_id'),
        ];

        if ($employee->can('payments.suspense')) {
            $this->assertRecoverable($loan);
            $payment = $this->payments->recordConfirmed($loan, $data, $employee);
            $recovery = LoanRecovery::where('payment_id', $payment->id)->whereNull('reversed_at')->latest('id')->firstOrFail();

            return $this->message('Recovery of TZS '.money($recovery->amount).' recorded: principal '.money($recovery->principal_amount).', penalty '.money($recovery->penalty_amount).', interest '.money($recovery->interest_amount).' (reserve '.money($recovery->reserve_amount).'), insurance '.money($recovery->insurance_amount).'.', 201, [
                'data' => ['recovery_id' => $recovery->id, 'payment_id' => $payment->id, 'amount' => (float) $recovery->amount, 'components' => $recovery->componentAmounts(), 'recovery' => $this->recoveries->position($loan)],
            ]);
        }

        $this->assertRecoverable($loan);
        $available = $this->payments->availableForReceipt($loan, 'amount');
        if ($data['amount'] > $available + 0.001) {
            throw ValidationException::withMessages(['amount' => 'Amount exceeds the unrecovered write-off balance still receivable of '.money(max(0, $available)).'.']);
        }
        if ($method !== 'CASH' && blank($data['transaction_id'])) {
            throw ValidationException::withMessages(['transaction_id' => 'Enter the transaction ID of the bank / mobile money receipt.']);
        }
        $payment = $method === 'CASH'
            ? $this->payments->recordCash($loan, $data['amount'], 'CASH', $employee)
            : $this->payments->recordBranchReceipt($loan, $data, $employee);

        return $this->message('TZS '.money($payment->amount).' received for the written-off loan and held pending Finance ('.$payment->status->label().'). It becomes a recovery only when Finance confirms it.', 202, [
            'data' => ['payment_id' => $payment->id, 'receipt_number' => $payment->receipt_number, 'status' => $payment->status->value, 'recovery' => $this->recoveries->position($loan)],
        ]);
    }

    /**
     * POST /loans/{loan}/recoveries/{recovery}/reverse
     */
    public function reverse(ReverseRepaymentRequest $request, Loan $loan, LoanRecovery $recovery): JsonResponse
    {
        $this->ensureVisible($loan);
        abort_unless((int) $recovery->loan_id === (int) $loan->id, 404);

        $result = $this->recoveries->reverse($recovery, $request->string('reason')->toString(), $this->currentEmployee());
        $message = 'Recovery reversed successfully. TZS '.money($result['recovery']->amount).' returned to suspense (receipt '.$result['payment']->receipt_number.').';
        if ($result['closed_period'] !== null) {
            $message .= " The recovery belongs to the closed period {$result['closed_period']}; the reversal was posted today as an adjustment in the current open period.";
        }

        return $this->message($message, 200, ['reversal_reference' => $result['reversal']->reference, 'payment_id' => $result['payment']->id, 'payment_status' => PaymentStatus::Unallocated->value]);
    }

    /**
     * The written-off loan must still take a recovery: written off, split known and not fully recovered.
     *
     * @throws ValidationException
     */
    private function assertRecoverable(Loan $loan): void
    {
        $loan->refresh();
        if ($loan->writeOff === null || $loan->status !== LoanStatus::WrittenOff) {
            throw ValidationException::withMessages(['amount' => 'Recoveries can only be recorded on a written-off loan.']);
        }
        $position = $this->recoveries->position($loan);
        if ($position['components_status'] === LoanRecoveryService::COMPONENTS_AMBIGUOUS) {
            throw ValidationException::withMessages(['amount' => LoanRecoveryService::AMBIGUOUS_MESSAGE]);
        }
        if ($position['unrecovered'] <= 0.004) {
            throw ValidationException::withMessages(['amount' => 'This written-off loan is already fully recovered.']);
        }
    }
}
