<?php

namespace App\Services;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Integrations\Payments\PaymentNotification;
use App\Models\ApprovalPolicy;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanRecovery;
use App\Models\LoanTransaction;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\TellerDeposit;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Reports\Financial\ControlReports;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Repayment channels (Documents: 💰 REPAYMENT OVERVIEW; C6 maker/checker):
 *  1. Direct payment (webhook, provider-confirmed) → match loan → auto allocate → SMS; no match → Suspense.
 *  2. Unmatched payment → Suspense → Finance allocates to a loan.
 *  3. Cash → Teller (PENDING_VERIFICATION) → bank deposit slip → Finance verify → confirm → allocate → SMS.
 *  4. Finance-entered payment, single step ({@see recordConfirmed()}): ENTER → CONFIRMED → ALLOCATED → POSTED in one transaction.
 *  5. Branch / teller non-cash receipt (mobile money / bank, {@see recordBranchReceipt()}): PENDING_APPROVAL with no journal and
 *     no loan, income or profit effect → Finance approves (a different user) → CONFIRMED → ALLOCATED → POSTED, or rejects.
 * Money a branch has received but Finance has not confirmed changes no loan balance, income, profit, commission or dividend.
 *
 * Loan allocation always goes through LoanService::allocate()/deposit(): the principal due on the instalments reached so
 * far, then penalty, then interest, then anything left against the outstanding principal (specification §10).
 *
 * Pending / unverified receipts (specification §11): there is ONE central Suspense account, held at HQ (branch = null).
 * Branches never get a pending account of their own; every receipt keeps its branch on the payment and on the journal
 * entry, so HQ sees the total and each branch sees its own share (SUSPENSE report, {@see ControlReports::suspense()}).
 *
 * Ledger (every step is balanced and immutable):
 *  - teller cash received:      Dr Teller Cash (branch, teller)  Cr Suspense (HQ)
 *  - cash confirmed:            Dr Bank (slip bank A/C)          Cr Teller Cash
 *  - unmatched / excess money:  Dr Bank                          Cr Suspense
 *  - any allocation to a loan:  Dr Suspense                      Cr Bank, then LoanService::deposit()
 *    (Dr Principal/Penalty/Interest/Insurance A/C, Cr Loan Receivable / income) — so held money moves
 *    into the branch fund accounts exactly like a live teller deposit.
 *  - rejected teller cash:      Ledger::reverse() of the receipt entry.
 *  - confirmed money for a WRITTEN-OFF loan (Finance entry, suspense allocation, approved branch receipt, confirmed teller
 *    cash, or a provider payment whose reference names the loan) is a recovery ({@see LoanRecoveryService}, C3 Option B):
 *    Dr Suspense / Cr Bank, then the component journal (Principal → Penalty → Interest → Insurance: Dr PRINCIPAL A/C / Cr
 *    WRITE-OFF EXPENSE, Dr PENALTY A/C / Cr PENALTY INCOME, Dr INTEREST A/C + RESERVE A/C / Cr INTEREST INCOME + INTEREST
 *    RESERVE, Dr INSURANCE A/C / Cr INSURANCE RESERVE); the loan stays written off and the part above the unrecovered
 *    write-off stays in suspense.
 * Inferred: the bank leg is posted against the bank account on the slip (or the company-level bank
 * clearing account for provider receipts) and cleared on allocation, because this ledger holds branch
 * money in the fund accounts that LoanService::deposit() debits.
 */
class PaymentService
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly LoanService $loans,
    ) {}

    /**
     * Loan currently accepting repayments for a customer.
     */
    public function repayableLoan(Customer $customer): ?Loan
    {
        return $customer->loans()->whereIn('status', LoanStatus::values(...LoanStatus::repayable()))->latest('id')->first();
    }

    /**
     * Loan a branch receipt of the customer goes to: the repayable loan, else the latest written-off loan (a recovery).
     */
    public function receivableLoan(Customer $customer): ?Loan
    {
        return $this->repayableLoan($customer)
            ?? $customer->loans()->where('status', LoanStatus::WrittenOff->value)->latest('id')->first();
    }

    /**
     * Branch money recorded but not yet confirmed by Finance for a loan (teller cash pending verification / deposited and
     * non-cash receipts pending approval).
     */
    public function pendingCash(Loan $loan): float
    {
        return round((float) Payment::where('loan_id', $loan->id)
            ->whereIn('status', PaymentStatus::values(...PaymentStatus::awaitingFinance()))
            ->sum('amount'), 2);
    }

    /**
     * What a new branch receipt may still bring for the loan: the outstanding balance of a repayable loan, or the unrecovered
     * write-off of a written-off loan, less branch money already awaiting Finance. Throws when the loan takes no money.
     *
     * @throws ValidationException
     */
    public function availableForReceipt(Loan $loan, string $field = 'depost'): float
    {
        if ($loan->status === LoanStatus::WrittenOff) {
            $position = app(LoanRecoveryService::class)->position($loan);
            if ($position['components_status'] === LoanRecoveryService::COMPONENTS_AMBIGUOUS) {
                throw ValidationException::withMessages([$field => LoanRecoveryService::AMBIGUOUS_MESSAGE]);
            }
            $balance = $position['unrecovered'];
        } elseif (in_array($loan->status, LoanStatus::repayable(), true)) {
            $balance = $this->loans->outstanding($loan)['total'];
        } else {
            throw ValidationException::withMessages([$field => 'This loan is not active.']);
        }

        return round($balance - $this->pendingCash($loan), 2);
    }

    /**
     * Teller receives a customer payment (POST /payments/cash) — CASH, or BANK / MNO with its bank or network (provider). Every method
     * follows the cash process: held as PENDING_VERIFICATION, banked on a deposit slip, posted when Finance verifies the slip.
     * The loan row is locked and the available balance (outstanding − branch money already pending) re-checked inside the
     * transaction, so concurrent receipts cannot exceed the balance. Cash for a written-off loan is accepted up to the
     * unrecovered write-off and becomes a recovery only when Finance confirms the deposit.
     */
    public function recordCash(Loan $loan, float $amount, string $method, Employee $teller, ?CarbonImmutable $date = null, ?string $provider = null): Payment
    {
        $date ??= CarbonImmutable::today();

        return DB::transaction(function () use ($loan, $amount, $method, $teller, $date, $provider): Payment {
            $loan = Loan::whereKey($loan->id)->lockForUpdate()->with('customer')->firstOrFail();

            $available = $this->availableForReceipt($loan);
            if ($amount > $available + 0.001) {
                throw ValidationException::withMessages(['depost' => 'Amount exceeds the outstanding balance of '.money(max(0, $available)).'.']);
            }

            $payment = Payment::create([
                'company_id' => $loan->company_id,
                'branch_id' => $loan->branch_id,
                'customer_id' => $loan->customer_id,
                'loan_id' => $loan->id,
                'employee_id' => $teller->id,
                'source' => Payment::SOURCE_TELLER,
                'channel' => strtoupper($method),
                'provider' => (string) ($provider ?? ''),
                'reference' => $loan->reference_number ?? $loan->loan_number,
                'phone' => $loan->customer->phone,
                'amount' => $amount,
                'status' => PaymentStatus::PendingVerification,
                'paid_on' => $date->toDateString(),
            ]);

            $entry = $this->ledger->journal($loan->company_id, 'TELLER CASH '.$loan->loan_number, [
                ['account' => Account::TellerCash, 'branch' => $loan->branch_id, 'employee' => $teller->id, 'debit' => $amount],
                ['account' => Account::Suspense, 'branch' => null, 'credit' => $amount],
            ], $payment, $date, $loan->branch_id, $teller);

            $payment->forceFill(['journal_entry_id' => $entry->id, 'receipt_number' => $this->receiptNumber($payment)])->save();

            return $payment;
        });
    }

    /**
     * Teller banks the cash: a deposit slip covering one or more pending receipts of the teller's branch.
     * The slip amount the teller types (what is written on the paper slip) must equal the server total of the locked
     * receipts; it is never trusted on its own.
     *
     * @param  array{bank_account_id: int, slip_number: string, amount: float, deposit_date: string}  $data
     * @param  list<int>  $paymentIds
     */
    public function submitBankDeposit(Employee $teller, int $branchId, array $data, array $paymentIds): TellerDeposit
    {
        return DB::transaction(function () use ($teller, $branchId, $data, $paymentIds): TellerDeposit {
            $payments = Payment::whereKey($paymentIds)
                ->where('company_id', $teller->company_id)
                ->where('branch_id', $branchId)
                ->where('source', Payment::SOURCE_TELLER)
                ->where('status', PaymentStatus::PendingVerification->value)
                ->lockForUpdate()
                ->get();

            if ($payments->count() !== count(array_unique($paymentIds))) {
                throw ValidationException::withMessages(['payment_ids' => 'Some receipts are not pending verification in this branch.']);
            }

            $expected = round((float) $payments->sum('amount'), 2);
            if (abs(round((float) $data['amount'], 2) - $expected) > 0.005) {
                throw ValidationException::withMessages(['amount' => 'The slip amount must equal the selected receipts (TZS '.number_format($expected, 2).').']);
            }

            $deposit = TellerDeposit::create([
                'company_id' => $teller->company_id,
                'branch_id' => $branchId,
                'employee_id' => $teller->id,
                'bank_account_id' => $data['bank_account_id'],
                'slip_number' => $data['slip_number'],
                'amount' => $expected,
                'deposit_date' => $data['deposit_date'],
                'status' => TellerDeposit::STATUS_PENDING,
            ]);

            foreach ($payments as $payment) {
                $payment->update(['teller_deposit_id' => $deposit->id, 'bank_account_id' => $deposit->bank_account_id, 'status' => PaymentStatus::Deposited]);
            }

            return $deposit;
        });
    }

    /**
     * The teller corrects a slip Finance found MISMATCHED against the bank statement: bank, slip number, date and which receipts it
     * covers. Only the teller who submitted the slip may do this, and only while it is MISMATCH. Receipts taken off the slip go back
     * to PENDING_VERIFICATION (still to bank); the slip returns to PENDING with the old statement check cleared, so Finance verifies
     * it again. Nothing is posted — a slip posts only when Finance verifies it.
     *
     * @param  array{bank_account_id: int, slip_number: string, amount: float, deposit_date: string}  $data
     * @param  list<int>  $paymentIds
     */
    public function updateBankDeposit(Employee $teller, TellerDeposit $deposit, array $data, array $paymentIds): TellerDeposit
    {
        return DB::transaction(function () use ($teller, $deposit, $data, $paymentIds): TellerDeposit {
            $locked = TellerDeposit::whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== TellerDeposit::STATUS_MISMATCH) {
                throw ValidationException::withMessages(['slip_number' => 'Only a slip Finance marked as MISMATCH can be edited.']);
            }
            if ((int) $locked->employee_id !== (int) $teller->id) {
                throw new AccessDeniedHttpException('Only the teller who submitted this slip can edit it.');
            }

            $payments = Payment::whereKey($paymentIds)
                ->where('company_id', $locked->company_id)
                ->where('branch_id', $locked->branch_id)
                ->where('source', Payment::SOURCE_TELLER)
                ->where(fn ($query) => $query->where('teller_deposit_id', $locked->id)->orWhere('status', PaymentStatus::PendingVerification->value))
                ->lockForUpdate()
                ->get();
            if ($payments->count() !== count(array_unique($paymentIds))) {
                throw ValidationException::withMessages(['payment_ids' => 'Some receipts are not on this slip or not pending verification in this branch.']);
            }

            $expected = round((float) $payments->sum('amount'), 2);
            if (abs(round((float) $data['amount'], 2) - $expected) > 0.005) {
                throw ValidationException::withMessages(['amount' => 'The slip amount must equal the selected receipts (TZS '.number_format($expected, 2).').']);
            }

            Payment::where('teller_deposit_id', $locked->id)->whereNotIn('id', $payments->modelKeys())
                ->update(['teller_deposit_id' => null, 'bank_account_id' => null, 'status' => PaymentStatus::PendingVerification->value]);

            $locked->update([
                'bank_account_id' => $data['bank_account_id'],
                'slip_number' => $data['slip_number'],
                'amount' => $expected,
                'deposit_date' => $data['deposit_date'],
                'status' => TellerDeposit::STATUS_PENDING,
                'statement_amount' => null,
                'statement_reference' => null,
                'verified_by' => null,
                'verified_at' => null,
            ]);
            foreach ($payments as $payment) {
                $payment->update(['teller_deposit_id' => $locked->id, 'bank_account_id' => $locked->bank_account_id, 'status' => PaymentStatus::Deposited]);
            }

            return $locked;
        });
    }

    /**
     * Finance matches a slip with the bank statement (POST /finance/bank-reconciliation).
     * IF deposit matches → verified, ELSE → keep pending (status "mismatch" for investigation).
     */
    public function verifyDeposit(TellerDeposit $deposit, float $statementAmount, string $statementReference, Employee $finance): TellerDeposit
    {
        return DB::transaction(function () use ($deposit, $statementAmount, $statementReference, $finance): TellerDeposit {
            $locked = TellerDeposit::whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [TellerDeposit::STATUS_PENDING, TellerDeposit::STATUS_MISMATCH], true)) {
                throw ValidationException::withMessages(['statement_amount' => 'This deposit has already been processed.']);
            }

            $matches = abs($statementAmount - (float) $locked->amount) < 0.01 && abs((float) $locked->amount - $locked->expectedAmount()) < 0.01;

            $locked->update([
                'statement_amount' => $statementAmount,
                'statement_reference' => $statementReference,
                'status' => $matches ? TellerDeposit::STATUS_VERIFIED : TellerDeposit::STATUS_MISMATCH,
                'verified_by' => $finance->id,
                'verified_at' => now(),
            ]);
            $deposit->setRawAttributes($locked->getAttributes(), true);

            return $deposit;
        });
    }

    /**
     * Final confirmation (POST /payments/confirm): Dr Bank Cr Teller Cash, allocate each receipt to its loan (a written-off loan's
     * cash becomes a recovery), SMS. The slip and its receipts are locked and their statuses re-checked inside the transaction, so
     * a double confirmation posts once.
     */
    public function confirmDeposit(TellerDeposit $deposit, Employee $finance): TellerDeposit
    {
        return DB::transaction(function () use ($deposit, $finance): TellerDeposit {
            $locked = TellerDeposit::whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== TellerDeposit::STATUS_VERIFIED) {
                throw ValidationException::withMessages(['deposit' => 'Only verified deposits can be confirmed.']);
            }
            $deposit = $locked;
            $date = CarbonImmutable::today();
            $payments = $deposit->payments()->with(['loan', 'customer'])->lockForUpdate()->get();
            if ($payments->contains(fn (Payment $payment): bool => $payment->status !== PaymentStatus::Deposited)) {
                throw ValidationException::withMessages(['deposit' => 'Some receipts of this deposit are no longer waiting for confirmation.']);
            }

            foreach ($payments->groupBy('employee_id') as $tellerId => $tellerPayments) {
                $total = round((float) $tellerPayments->sum('amount'), 2);
                $this->ledger->journal($deposit->company_id, 'TELLER CASH BANKED '.$deposit->slip_number, [
                    ['account' => Account::Bank, 'bank' => $deposit->bank_account_id, 'debit' => $total],
                    ['account' => Account::TellerCash, 'branch' => $deposit->branch_id, 'employee' => $tellerId ?: null, 'credit' => $total],
                ], $deposit, $date, $deposit->branch_id, $finance);
            }

            foreach ($payments as $payment) {
                $payment->update(['status' => PaymentStatus::Confirmed, 'verified_by' => $finance->id, 'verified_at' => now()]);
                $excess = $payment->loan->status === LoanStatus::WrittenOff
                    ? round((float) $payment->amount - $this->applyRecovery($payment, $payment->loan, (float) $payment->amount, $date, $finance), 2)
                    : $this->applyToLoan($payment, $payment->loan, (float) $payment->amount, $date, $finance);
                if ($excess > 0) {
                    $this->holdExcess($payment, $excess, $date, alreadyInSuspense: true);
                }
                $this->notifyPayment($payment, $payment->loan, (float) $payment->amount - $excess);
            }

            $deposit->update(['status' => TellerDeposit::STATUS_CONFIRMED, 'confirmed_by' => $finance->id, 'confirmed_at' => now()]);

            return $deposit;
        });
    }

    /**
     * Finance rejects a slip (teller has not deposited / amount mismatch): receipts return to pending.
     */
    public function rejectDeposit(TellerDeposit $deposit, string $reason, Employee $finance): TellerDeposit
    {
        return DB::transaction(function () use ($deposit, $reason, $finance): TellerDeposit {
            $deposit = TellerDeposit::whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if (in_array($deposit->status, [TellerDeposit::STATUS_CONFIRMED, TellerDeposit::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages(['reason' => 'This deposit has already been processed.']);
            }

            foreach ($deposit->payments()->lockForUpdate()->get() as $payment) {
                $payment->update(['teller_deposit_id' => null, 'bank_account_id' => null, 'status' => PaymentStatus::PendingVerification]);
            }
            $deposit->update(['status' => TellerDeposit::STATUS_REJECTED, 'rejection_reason' => $reason, 'verified_by' => $finance->id, 'verified_at' => now()]);

            return $deposit;
        });
    }

    /**
     * Finance rejects a teller cash receipt (e.g. wrong entry): the receipt entry is reversed.
     */
    public function rejectCash(Payment $payment, string $reason, Employee $finance): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $finance): Payment {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->source !== Payment::SOURCE_TELLER || $payment->status !== PaymentStatus::PendingVerification) {
                throw ValidationException::withMessages(['reason' => 'Only cash pending verification can be rejected.']);
            }

            if ($payment->journalEntry !== null) {
                $this->ledger->reverse($payment->journalEntry, 'CASH REJECTED: '.$reason);
            }
            $payment->update(['status' => PaymentStatus::Rejected, 'rejection_reason' => $reason, 'verified_by' => $finance->id, 'verified_at' => now()]);

            return $payment;
        });
    }

    /**
     * Direct payment webhook. Idempotent by channel + transaction_id.
     *
     * @return array{status: string, payment: Payment}
     */
    public function receive(PaymentNotification $notification): array
    {
        $existing = Payment::where('channel', $notification->channel)->where('transaction_id', $notification->transactionId)->first();
        if ($existing !== null) {
            return $this->duplicate($existing, $notification);
        }

        $loan = $this->matchLoan($notification);
        $companyId = $loan?->company_id ?? $this->defaultCompanyId();
        if ($companyId === null) {
            throw ValidationException::withMessages(['reference' => 'Payment could not be matched to a company.']);
        }

        try {
            return DB::transaction(function () use ($notification, $loan, $companyId): array {
                $date = $notification->paidOn ? CarbonImmutable::parse($notification->paidOn)->startOfDay() : CarbonImmutable::today();
                $payment = Payment::create([
                    'company_id' => $companyId,
                    'branch_id' => $loan?->branch_id,
                    'customer_id' => $loan?->customer_id,
                    'loan_id' => $loan?->id,
                    'source' => Payment::SOURCE_WEBHOOK,
                    'channel' => $notification->channel,
                    'reference' => $notification->reference,
                    'transaction_id' => $notification->transactionId,
                    'phone' => $notification->phone,
                    'amount' => $notification->amount,
                    'status' => $loan ? PaymentStatus::Allocated : PaymentStatus::Unallocated,
                    'paid_on' => $date->toDateString(),
                    'note' => $loan ? null : 'Invalid or missing reference',
                    'payload' => $notification->payload,
                ]);
                $payment->forceFill(['receipt_number' => $this->receiptNumber($payment)])->save();

                if ($loan === null) {
                    $this->receiveIntoSuspense($payment, $notification->amount, $date);

                    return ['status' => 'SUSPENSE', 'payment' => $payment];
                }

                $excess = $loan->status === LoanStatus::WrittenOff
                    ? round($notification->amount - $this->applyRecovery($payment, $loan, $notification->amount, $date, null, fromSuspense: false), 2)
                    : $this->applyToLoan($payment, $loan, $notification->amount, $date, null, fromSuspense: false);
                if ($excess > 0) {
                    $this->holdExcess($payment, $excess, $date, alreadyInSuspense: false);
                }
                $this->notifyPayment($payment, $loan, $notification->amount);

                return ['status' => 'PAYMENT_SUCCESS', 'payment' => $payment];
            });
        } catch (QueryException $exception) {
            $existing = Payment::where('channel', $notification->channel)->where('transaction_id', $notification->transactionId)->first();
            if ($existing !== null) {
                return $this->duplicate($existing, $notification);
            }
            throw $exception;
        }
    }

    /**
     * Finance records an unmatched payment found on a bank/mobile statement (POST /payments/unmatched).
     *
     * @param  array{amount: float, channel: string, provider?: string|null, reference?: string|null, transaction_id?: string|null, phone?: string|null, paid_on: string, branch_id?: int|null, note?: string|null}  $data
     */
    public function recordUnmatched(Company|int $company, array $data, Employee $finance): Payment
    {
        return DB::transaction(function () use ($company, $data, $finance): Payment {
            $date = CarbonImmutable::parse($data['paid_on']);
            $payment = Payment::create([
                'company_id' => $company instanceof Company ? $company->id : $company,
                'branch_id' => $data['branch_id'] ?? null,
                'employee_id' => $finance->id,
                'source' => Payment::SOURCE_MANUAL,
                'channel' => strtoupper($data['channel']),
                'provider' => (string) ($data['provider'] ?? ''),
                'reference' => $data['reference'] ?? self::newReference(),
                'transaction_id' => $data['transaction_id'] ?? self::newTransactionId(),
                'phone' => $data['phone'] ?? null,
                'amount' => $data['amount'],
                'status' => PaymentStatus::Unallocated,
                'paid_on' => $date->toDateString(),
                'note' => $data['note'] ?? null,
            ]);
            $payment->forceFill(['receipt_number' => $this->receiptNumber($payment)])->save();
            $this->receiveIntoSuspense($payment, (float) $data['amount'], $date, $finance);

            return $payment;
        });
    }

    /**
     * Finance allocates suspense money to a loan (POST /payments/allocate). Money beyond the loan's
     * outstanding balance stays in suspense on the same record.
     */
    public function allocateSuspense(Payment $payment, Loan $loan, float $amount, Employee $finance): Payment
    {
        return DB::transaction(function () use ($payment, $loan, $amount, $finance): Payment {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $loan = Loan::whereKey($loan->id)->lockForUpdate()->with('customer')->firstOrFail();

            if ($payment->status !== PaymentStatus::Unallocated) {
                throw ValidationException::withMessages(['loan_id' => 'Only unallocated suspense payments can be allocated.']);
            }
            if ($amount > $payment->unallocated_amount + 0.001) {
                throw ValidationException::withMessages(['amount' => 'Amount exceeds the unallocated balance of '.money($payment->unallocated_amount).'.']);
            }
            if ($loan->status === LoanStatus::WrittenOff) {
                $this->availableForReceipt($loan, 'amount');
                $recovered = $this->applyRecovery($payment, $loan, $amount, CarbonImmutable::today(), $finance);
                if ($recovered <= 0) {
                    throw ValidationException::withMessages(['amount' => 'Nothing is left to recover on this written-off loan.']);
                }

                return $this->afterAllocation($payment, $loan, $recovered, $finance);
            }
            if (! in_array($loan->status, LoanStatus::repayable(), true)) {
                throw ValidationException::withMessages(['loan_id' => 'This loan is not active.']);
            }
            $outstanding = $this->loans->outstanding($loan)['total'];
            if ($amount > $outstanding + 0.001) {
                throw ValidationException::withMessages(['amount' => 'Amount exceeds the outstanding balance of '.money($outstanding).'.']);
            }

            $this->applyToLoan($payment, $loan, $amount, CarbonImmutable::today(), $finance);

            return $this->afterAllocation($payment, $loan, $amount, $finance);
        });
    }

    private function afterAllocation(Payment $payment, Loan $loan, float $amount, Employee $finance): Payment
    {
        $payment->refresh();
        $payment->update([
            'customer_id' => $payment->customer_id ?? $loan->customer_id,
            'status' => $payment->unallocated_amount <= 0.001 ? PaymentStatus::Allocated : PaymentStatus::Unallocated,
            'verified_by' => $finance->id,
            'verified_at' => now(),
        ]);
        $this->notifyPayment($payment, $loan, $amount);

        return $payment;
    }

    /**
     * Finance-entered payment, single step (C6: ENTER → CONFIRMED → ALLOCATED → POSTED), in one transaction with the loan row
     * locked: the payment is recorded CONFIRMED, the money received (Dr BANK — the bank clearing account; the channel's provider is only a bank or network name /
     * Cr SUSPENSE), then allocated (Dr SUSPENSE / Cr BANK and the repayment journal of {@see LoanService::deposit()}, or the
     * recovery journal for a written-off loan) and the payment becomes ALLOCATED. More than the loan's outstanding balance (or the
     * unrecovered write-off) is rejected; a fully recovered or ambiguous write-off takes no entry. transaction_id is unique per
     * channel (a concurrent duplicate is rejected by the database key).
     *
     * @param  array{amount: float|int|string, channel: string, provider?: string|null, reference?: string|null, transaction_id?: string|null, paid_on?: string|null, note?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function recordConfirmed(Loan $loan, array $data, Employee $finance): Payment
    {
        try {
            return DB::transaction(function () use ($loan, $data, $finance): Payment {
                $loan = Loan::whereKey($loan->id)->lockForUpdate()->with('customer')->firstOrFail();
                $amount = round((float) $data['amount'], 2);
                $date = ! empty($data['paid_on']) ? CarbonImmutable::parse($data['paid_on'])->startOfDay() : CarbonImmutable::today();

                if ($loan->status === LoanStatus::WrittenOff) {
                    $position = app(LoanRecoveryService::class)->position($loan);
                    if ($position['components_status'] === LoanRecoveryService::COMPONENTS_AMBIGUOUS) {
                        throw ValidationException::withMessages(['amount' => LoanRecoveryService::AMBIGUOUS_MESSAGE]);
                    }
                    if ($position['unrecovered'] <= 0.004) {
                        throw ValidationException::withMessages(['amount' => 'This written-off loan is already fully recovered.']);
                    }
                    $balance = $position['unrecovered'];
                    $label = 'the unrecovered write-off balance';
                } elseif (in_array($loan->status, LoanStatus::repayable(), true)) {
                    $balance = $this->loans->outstanding($loan)['total'];
                    $label = 'the outstanding balance';
                } else {
                    throw ValidationException::withMessages(['loan_id' => 'This loan is not active.']);
                }
                if ($amount > $balance + 0.001) {
                    throw ValidationException::withMessages(['amount' => 'Amount exceeds '.$label.' of '.money($balance).'.']);
                }

                $payment = Payment::create([
                    'company_id' => $loan->company_id,
                    'branch_id' => $loan->branch_id,
                    'customer_id' => $loan->customer_id,
                    'loan_id' => $loan->id,
                    'employee_id' => $finance->id,
                    // Received into the bank clearing account: the provider is a bank or network name, not a company bank account.
                    'bank_account_id' => null,
                    'source' => Payment::SOURCE_MANUAL,
                    'channel' => strtoupper($data['channel']),
                    'provider' => (string) ($data['provider'] ?? ''),
                    'reference' => $data['reference'] ?? self::newReference(),
                    'transaction_id' => $data['transaction_id'] ?? self::newTransactionId(),
                    'phone' => $loan->customer?->phone,
                    'amount' => $amount,
                    'status' => PaymentStatus::Confirmed,
                    'paid_on' => $date->toDateString(),
                    'note' => $data['note'] ?? null,
                    'verified_by' => $finance->id,
                    'verified_at' => now(),
                ]);
                $payment->forceFill(['receipt_number' => $this->receiptNumber($payment)])->save();
                $this->receiveIntoSuspense($payment, $amount, $date, $finance);

                if ($loan->status === LoanStatus::WrittenOff) {
                    $this->applyRecovery($payment, $loan, $amount, $date, $finance);
                } else {
                    $this->applyToLoan($payment, $loan, $amount, $date, $finance);
                }

                $payment->refresh();
                $payment->update(['status' => $payment->unallocated_amount <= 0.001 ? PaymentStatus::Allocated : PaymentStatus::Unallocated]);
                $this->notifyPayment($payment, $loan, (float) $payment->allocated_amount);

                return $payment;
            });
        } catch (QueryException $exception) {
            if (! empty($data['transaction_id']) && Payment::where('channel', strtoupper($data['channel']))->where('transaction_id', $data['transaction_id'])->exists()) {
                throw ValidationException::withMessages(['transaction_id' => 'This transaction ID has already been recorded for this channel.']);
            }
            throw $exception;
        }
    }

    /**
     * Branch / teller-entered non-cash receipt (mobile money or bank, C6): recorded PENDING_APPROVAL with its channel and
     * transaction ID. Nothing is posted — no journal, no loan balance, income, profit, commission or dividend effect — until
     * Finance approves it ({@see approveBranchReceipt()}). The loan row is locked and the amount checked against the outstanding
     * balance (or unrecovered write-off) less branch money already awaiting Finance.
     *
     * @param  array{amount: float|int|string, channel: string, provider?: string|null, transaction_id?: string|null, reference?: string|null, bank_account_id?: int|null, paid_on?: string|null, note?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function recordBranchReceipt(Loan $loan, array $data, Employee $teller): Payment
    {
        try {
            return DB::transaction(function () use ($loan, $data, $teller): Payment {
                $loan = Loan::whereKey($loan->id)->lockForUpdate()->with('customer')->firstOrFail();
                $amount = round((float) $data['amount'], 2);

                $available = $this->availableForReceipt($loan, 'amount');
                if ($amount > $available + 0.001) {
                    throw ValidationException::withMessages(['amount' => 'Amount exceeds the balance still receivable of '.money(max(0, $available)).'.']);
                }

                $payment = Payment::create([
                    'company_id' => $loan->company_id,
                    'branch_id' => $loan->branch_id,
                    'customer_id' => $loan->customer_id,
                    'loan_id' => $loan->id,
                    'employee_id' => $teller->id,
                    'bank_account_id' => $data['bank_account_id'] ?? null,
                    'source' => Payment::SOURCE_TELLER,
                    'channel' => strtoupper($data['channel']),
                    'provider' => (string) ($data['provider'] ?? ''),
                    'reference' => $data['reference'] ?? self::newReference(),
                    'transaction_id' => $data['transaction_id'] ?? self::newTransactionId(),
                    'phone' => $loan->customer?->phone,
                    'amount' => $amount,
                    'status' => PaymentStatus::PendingApproval,
                    'paid_on' => ! empty($data['paid_on']) ? CarbonImmutable::parse($data['paid_on'])->toDateString() : CarbonImmutable::today()->toDateString(),
                    'note' => $data['note'] ?? null,
                ]);
                $payment->forceFill(['receipt_number' => $this->receiptNumber($payment)])->save();

                return $payment;
            });
        } catch (QueryException $exception) {
            if (! empty($data['transaction_id']) && Payment::where('channel', strtoupper($data['channel']))->where('transaction_id', $data['transaction_id'])->exists()) {
                throw ValidationException::withMessages(['transaction_id' => 'This transaction ID has already been recorded for this channel.']);
            }
            throw $exception;
        }
    }

    /**
     * Finance approves a pending branch receipt (C6), in one transaction with the payment and loan rows locked and the status
     * re-checked (a double approval posts once): CONFIRMED, the money received (Dr BANK — the chosen bank A/C, the one on the
     * receipt, or the bank clearing account / Cr SUSPENSE), then allocated to the loan like a Finance entry (repayment, or recovery
     * for a written-off loan). What no longer fits the loan (paid meanwhile, fully recovered, ambiguous write-off) stays in suspense
     * on the same payment, UNALLOCATED. The employee who recorded the receipt cannot approve it unless self-approval is granted.
     *
     * @throws ValidationException
     */
    public function approveBranchReceipt(Payment $payment, Employee $finance, ?int $bankAccountId = null): Payment
    {
        return DB::transaction(function () use ($payment, $finance, $bankAccountId): Payment {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== PaymentStatus::PendingApproval) {
                throw ValidationException::withMessages(['payment' => 'Only branch receipts pending approval can be approved.']);
            }
            app(SegregationOfDuties::class)->assertCanApprove($payment->employee_id, $finance, 'branch receipt', workflow: ApprovalPolicy::BRANCH_RECEIPTS);

            $loan = Loan::whereKey($payment->loan_id)->lockForUpdate()->with('customer')->firstOrFail();
            $date = CarbonImmutable::today();
            $payment->update([
                'bank_account_id' => $bankAccountId ?? $payment->bank_account_id,
                'status' => PaymentStatus::Confirmed,
                'verified_by' => $finance->id,
                'verified_at' => now(),
            ]);
            $amount = round((float) $payment->amount, 2);
            $this->receiveIntoSuspense($payment, $amount, $date, $finance);

            if ($loan->status === LoanStatus::WrittenOff) {
                $this->applyRecovery($payment, $loan, $amount, $date, $finance);
            } elseif (in_array($loan->status, LoanStatus::repayable(), true)) {
                $this->applyToLoan($payment, $loan, $amount, $date, $finance);
            }

            $payment->refresh();
            $payment->update(['status' => $payment->unallocated_amount <= 0.001 ? PaymentStatus::Allocated : PaymentStatus::Unallocated]);
            if ((float) $payment->allocated_amount > 0) {
                $this->notifyPayment($payment, $loan, (float) $payment->allocated_amount);
            }

            return $payment;
        });
    }

    /**
     * Finance rejects a pending branch receipt: nothing was posted; the receipt is kept REJECTED with the reason.
     *
     * @throws ValidationException
     */
    public function rejectBranchReceipt(Payment $payment, string $reason, Employee $finance): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $finance): Payment {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== PaymentStatus::PendingApproval) {
                throw ValidationException::withMessages(['reason' => 'Only branch receipts pending approval can be rejected.']);
            }
            app(SegregationOfDuties::class)->assertCanApprove($payment->employee_id, $finance, 'branch receipt', workflow: ApprovalPolicy::BRANCH_RECEIPTS);

            $payment->update(['status' => PaymentStatus::Rejected, 'rejection_reason' => $reason, 'verified_by' => $finance->id, 'verified_at' => now()]);

            return $payment;
        });
    }

    /**
     * Branch receipts waiting for Finance approval (C6), for the Pending Approvals area: count, total amount and rows.
     *
     * @param  list<int>|null  $branchIds  null = every branch
     * @return array{count: int, amount: float, rows: Collection<int, Payment>}
     */
    public function pendingBranchReceipts(int $companyId, ?array $branchIds = null): array
    {
        $rows = Payment::query()
            ->where('company_id', $companyId)
            ->where('status', PaymentStatus::PendingApproval->value)
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('branch_id', $branchIds))
            ->with(['customer', 'branch', 'employee', 'loan', 'bankAccount'])
            ->latest('paid_on')
            ->latest('id')
            ->get();

        return ['count' => $rows->count(), 'amount' => round((float) $rows->sum('amount'), 2), 'rows' => $rows->toBase()];
    }

    /**
     * Post (part of) confirmed money as a recovery on a written-off loan and record the allocation. Only the part that fits the
     * unrecovered write-off is posted; returns the amount recorded (the rest stays in suspense on the payment). A write-off whose
     * component split is ambiguous takes nothing (0): the money stays in suspense.
     */
    private function applyRecovery(Payment $payment, Loan $loan, float $amount, CarbonImmutable $date, ?Employee $employee, bool $fromSuspense = true): float
    {
        $recoveries = app(LoanRecoveryService::class);
        $loan = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
        $posted = round(min($amount, $recoveries->recoverable($loan)), 2);

        if ($posted <= 0) {
            return 0.0;
        }

        if ($fromSuspense) {
            $this->ledger->journal($payment->company_id, 'SUSPENSE ALLOCATION '.$loan->loan_number, [
                ['account' => Account::Suspense, 'branch' => null, 'debit' => $posted],
                ['account' => Account::Bank, 'bank' => $payment->bank_account_id, 'credit' => $posted],
            ], $payment, $date, $loan->branch_id, $employee);
        }

        $recovery = $recoveries->record($loan, $posted, $date, (string) $payment->channel, $payment->transaction_id ?? $payment->receipt_number, $employee, $payment);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'loan_id' => $loan->id,
            'loan_recovery_id' => $recovery->id,
            'employee_id' => $employee?->id,
            'amount' => $posted,
        ]);
        $payment->increment('allocated_amount', $posted);

        return $posted;
    }

    /**
     * Flag suspense money (fraud suspicion / wrong customer) — it stays in suspense.
     */
    public function flag(Payment $payment, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $reason): Payment {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! in_array($payment->status, PaymentStatus::suspense(), true)) {
                throw ValidationException::withMessages(['reason' => 'Only suspense payments can be flagged.']);
            }

            $payment->update(['status' => $payment->status === PaymentStatus::Flagged ? PaymentStatus::Unallocated : PaymentStatus::Flagged, 'flag_reason' => $reason]);

            return $payment;
        });
    }

    /**
     * Return unallocated suspense money to the payer (Documents: overpayment → refund): Dr Suspense Cr Bank.
     */
    public function refund(Payment $payment, string $reason, Employee $finance): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $finance): Payment {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! in_array($payment->status, PaymentStatus::suspense(), true) || $payment->unallocated_amount <= 0) {
                throw ValidationException::withMessages(['reason' => 'Only unallocated suspense money can be refunded.']);
            }

            $amount = $payment->unallocated_amount;
            $this->ledger->journal($payment->company_id, 'SUSPENSE REFUND '.$payment->receipt_number, [
                ['account' => Account::Suspense, 'branch' => null, 'debit' => $amount],
                ['account' => Account::Bank, 'bank' => $payment->bank_account_id, 'credit' => $amount],
            ], $payment, CarbonImmutable::today(), $payment->branch_id, $finance);

            $payment->update(['status' => PaymentStatus::Refunded, 'rejection_reason' => $reason, 'verified_by' => $finance->id, 'verified_at' => now()]);

            return $payment;
        });
    }

    /**
     * Customer statement: every loan transaction with its Principal / Penalty / Interest / Insurance split, and every standing
     * recovery on a written-off loan (type `recovery`, with its component split — a legacy recovery is interest only; it reduces
     * the remaining debit). Reversed
     * transactions and recoveries are left out, so they count in no remaining debit or total.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function statement(Customer $customer, ?string $from = null, ?string $to = null): Collection
    {
        $balances = [];
        $transactions = LoanTransaction::query()
            ->where('customer_id', $customer->id)
            ->whereNull('reversed_at')
            ->with(['loan:id,loan_number,reference_number,total_payable,insurance,opening_paid_principal', 'employee:id,first_name,last_name'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
        $receipts = PaymentAllocation::whereIn('loan_transaction_id', $transactions->modelKeys())
            ->with('payment:id,receipt_number,channel,transaction_id')
            ->get()
            ->keyBy('loan_transaction_id');
        $recoveries = LoanRecovery::query()
            ->where('customer_id', $customer->id)
            ->whereNull('reversed_at')
            ->with(['loan:id,loan_number,reference_number,total_payable,insurance,opening_paid_principal', 'employee:id,first_name,last_name', 'payment:id,receipt_number,transaction_id'])
            ->get();

        return $transactions->toBase()
            ->merge($recoveries)
            ->sortBy(fn (LoanTransaction|LoanRecovery $row): string => ($row instanceof LoanRecovery ? $row->recovered_on->toDateString().'|1|' : $row->transaction_date->toDateString().'|0|').str_pad((string) $row->id, 12, '0', STR_PAD_LEFT))
            ->values()
            ->map(function (LoanTransaction|LoanRecovery $transaction) use (&$balances, $receipts): array {
                if ($transaction instanceof LoanRecovery) {
                    return $this->recoveryStatementRow($transaction, $balances);
                }
                $loan = $transaction->loan;
                $key = $transaction->loan_id ?? 0;
                // A loan carried over from the old system opens at what was still owed to that system (its Remain Amount).
                $balances[$key] ??= $loan ? (float) $loan->total_payable + (float) $loan->insurance - (float) $loan->opening_paid_principal : 0.0;
                $isDeposit = $transaction->type === 'deposit';
                $isReversed = $transaction->reversed_at !== null;
                if ($isDeposit && ! $isReversed) {
                    $balances[$key] = max(0.0, $balances[$key] - (float) $transaction->amount);
                }
                $receipt = $receipts->get($transaction->id)?->payment;

                return [
                    'id' => $transaction->id,
                    'date' => $transaction->transaction_date->toDateString(),
                    'loan_id' => $transaction->loan_id,
                    'loan_number' => $loan?->loan_number,
                    'reference_number' => $loan?->reference_number,
                    'type' => $transaction->type,
                    'description' => $transaction->description,
                    'method' => $transaction->method,
                    'receipt_number' => $receipt?->receipt_number,
                    'transaction_id' => $receipt?->transaction_id,
                    'deposit' => $isDeposit ? (float) $transaction->amount : 0.0,
                    'withdrawal' => $isDeposit ? 0.0 : (float) $transaction->amount,
                    'principal' => (float) $transaction->principal,
                    'penalty' => (float) $transaction->penalty,
                    'interest' => (float) $transaction->interest,
                    'insurance' => (float) $transaction->insurance,
                    'reserve' => (float) $transaction->reserve,
                    'remain_debit' => round($balances[$key], 2),
                    'reversed' => $isReversed,
                    'reversed_at' => $transaction->reversed_at?->toDateTimeString(),
                    'reversal_reason' => $transaction->reversal_reason,
                    'employee' => $transaction->employee ? trim($transaction->employee->first_name.' '.$transaction->employee->last_name) : null,
                ];
            })
            ->filter(fn (array $row): bool => ($from === null || $row['date'] >= $from) && ($to === null || $row['date'] <= $to))
            ->values();
    }

    /**
     * @param  array<int, float>  $balances  running remaining debit per loan
     * @return array<string, mixed>
     */
    private function recoveryStatementRow(LoanRecovery $recovery, array &$balances): array
    {
        $loan = $recovery->loan;
        $key = $recovery->loan_id;
        $balances[$key] ??= $loan ? (float) $loan->total_payable + (float) $loan->insurance - (float) $loan->opening_paid_principal : 0.0;
        $balances[$key] = max(0.0, $balances[$key] - (float) $recovery->amount);

        return [
            'id' => $recovery->id,
            'date' => $recovery->recovered_on->toDateString(),
            'loan_id' => $recovery->loan_id,
            'loan_number' => $loan?->loan_number,
            'reference_number' => $loan?->reference_number,
            'type' => 'recovery',
            'description' => 'RECOVERY AFTER WRITE-OFF',
            'method' => $recovery->method,
            'receipt_number' => $recovery->payment?->receipt_number,
            'transaction_id' => $recovery->payment?->transaction_id ?? $recovery->reference,
            'deposit' => (float) $recovery->amount,
            'withdrawal' => 0.0,
            ...array_map(fn (float $value): float => round($value, 2), $recovery->componentAmounts()),
            'remain_debit' => round($balances[$key], 2),
            'reversed' => false,
            'reversed_at' => null,
            'reversal_reason' => null,
            'employee' => $recovery->employee ? trim($recovery->employee->first_name.' '.$recovery->employee->last_name) : null,
        ];
    }

    /**
     * Post (part of) a payment to a loan through LoanService and record the allocation.
     * Returns the part that did not fit the loan's outstanding balance.
     */
    private function applyToLoan(Payment $payment, Loan $loan, float $amount, CarbonImmutable $date, ?Employee $employee, bool $fromSuspense = true): float
    {
        $loan = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
        $allocation = $this->loans->allocate($loan, $amount, $date);
        $posted = round($amount - $allocation['excess'], 2);

        if ($posted > 0) {
            if ($fromSuspense) {
                $this->ledger->journal($payment->company_id, 'SUSPENSE ALLOCATION '.$loan->loan_number, [
                    ['account' => Account::Suspense, 'branch' => null, 'debit' => $posted],
                    ['account' => Account::Bank, 'bank' => $payment->bank_account_id, 'credit' => $posted],
                ], $payment, $date, $loan->branch_id, $employee);
            }

            $transaction = $this->loans->deposit($loan, $posted, $date, $payment->channel, $employee);

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'loan_id' => $loan->id,
                'loan_transaction_id' => $transaction->id,
                'employee_id' => $employee?->id,
                'amount' => $posted,
            ]);
            $payment->increment('allocated_amount', $posted);
        }

        return round($allocation['excess'], 2);
    }

    /**
     * A reversed loan repayment that came from a payment: the money goes back to SUSPENSE on that payment, unallocated,
     * so Finance can re-allocate or refund it. The repayment's fund-account entry has already been reversed by
     * LoanService::reverseRepayment(); the cash itself stays where the payment's earlier steps put it (banked teller cash
     * or the provider/bank receipt), so it is re-held exactly like an unmatched receipt:
     * Dr BANK (the payment's bank A/C, or the bank clearing account) / Cr SUSPENSE (the payment's branch).
     * Runs inside the caller's transaction.
     */
    public function returnReversedRepaymentToSuspense(PaymentAllocation $allocation, Employee $employee, string $what = 'REPAYMENT'): Payment
    {
        $payment = Payment::whereKey($allocation->payment_id)->lockForUpdate()->firstOrFail();
        $amount = round((float) $allocation->amount, 2);

        $entry = $this->ledger->journal($payment->company_id, $what.' REVERSED TO SUSPENSE '.$payment->receipt_number, [
            ['account' => Account::Bank, 'bank' => $payment->bank_account_id, 'debit' => $amount],
            ['account' => Account::Suspense, 'branch' => null, 'credit' => $amount],
        ], $payment, CarbonImmutable::today(), $payment->branch_id, $employee, TransactionType::SuspenseReceipt);

        $allocation->update(['reversed_at' => now(), 'reversal_journal_entry_id' => $entry->id]);
        $payment->update([
            'allocated_amount' => max(0.0, round((float) $payment->allocated_amount - $amount, 2)),
            'status' => PaymentStatus::Unallocated,
        ]);

        return $payment;
    }

    /**
     * A reversed loan repayment without a payment record (posted directly, before payments were tracked): the money is
     * held as a new unallocated suspense receipt of the loan's branch, posted like an unmatched receipt
     * (Dr BANK clearing / Cr SUSPENSE), so it can be re-allocated or refunded. Runs inside the caller's transaction.
     */
    public function holdReversedRepayment(LoanTransaction $deposit, Employee $employee): Payment
    {
        $loan = $deposit->loan;

        return $this->holdReversedMoney($loan, (float) $deposit->amount, (string) $deposit->method, 'Reversed repayment of loan '.($loan?->loan_number ?? '').' dated '.$deposit->transaction_date->toDateString(), $employee);
    }

    /**
     * Money of a reversed loan posting that was received directly (no payment record) — a repayment or a write-off recovery —
     * held as a new unallocated suspense receipt of the loan's branch (Dr BANK clearing / Cr SUSPENSE), so it can be
     * re-allocated or refunded. Runs inside the caller's transaction.
     */
    public function holdReversedMoney(Loan $loan, float $amount, string $method, string $note, Employee $employee): Payment
    {
        $payment = Payment::create([
            'company_id' => $loan->company_id,
            'branch_id' => $loan->branch_id,
            'customer_id' => $loan->customer_id,
            'loan_id' => $loan->id,
            'employee_id' => $employee->id,
            'source' => Payment::SOURCE_MANUAL,
            'channel' => strtoupper($method),
            'reference' => $loan->reference_number ?? $loan->loan_number,
            'phone' => $loan->customer?->phone,
            'amount' => round($amount, 2),
            'status' => PaymentStatus::Unallocated,
            'paid_on' => CarbonImmutable::today()->toDateString(),
            'note' => $note,
        ]);
        $payment->forceFill(['receipt_number' => $this->receiptNumber($payment)])->save();
        $this->receiveIntoSuspense($payment, (float) $payment->amount, CarbonImmutable::today(), $employee);

        return $payment;
    }

    /**
     * Overpayment: the excess stays with the customer as suspense credit (Documents: Wallet / Advance / Refund).
     */
    private function holdExcess(Payment $payment, float $excess, CarbonImmutable $date, bool $alreadyInSuspense): void
    {
        $credit = Payment::create([
            'company_id' => $payment->company_id,
            'branch_id' => $payment->branch_id,
            'customer_id' => $payment->customer_id,
            'employee_id' => $payment->employee_id,
            'parent_id' => $payment->id,
            'bank_account_id' => $payment->bank_account_id,
            'source' => $payment->source,
            'channel' => $payment->channel,
            'reference' => $payment->reference,
            'phone' => $payment->phone,
            'amount' => $excess,
            'status' => PaymentStatus::Unallocated,
            'paid_on' => $date->toDateString(),
            'note' => 'Overpayment of receipt '.$payment->receipt_number,
        ]);
        $credit->forceFill(['receipt_number' => $this->receiptNumber($credit)])->save();

        if ($alreadyInSuspense) {
            /** Teller cash excess: Suspense already credited at the teller and Bank debited at confirmation. */
            $payment->increment('allocated_amount', $excess);
        } else {
            $this->receiveIntoSuspense($credit, $excess, $date);
            $payment->increment('allocated_amount', $excess);
        }
    }

    private function receiveIntoSuspense(Payment $payment, float $amount, CarbonImmutable $date, ?Employee $employee = null): void
    {
        $entry = $this->ledger->journal($payment->company_id, 'SUSPENSE '.$payment->channel.' '.($payment->transaction_id ?? $payment->receipt_number), [
            ['account' => Account::Bank, 'bank' => $payment->bank_account_id, 'debit' => $amount],
            ['account' => Account::Suspense, 'branch' => null, 'credit' => $amount],
        ], $payment, $date, $payment->branch_id, $employee);

        $payment->forceFill(['journal_entry_id' => $entry->id])->save();
    }

    /**
     * Duplicate transaction_id: ignored and flagged in the audit trail.
     *
     * @return array{status: string, payment: Payment}
     */
    private function duplicate(Payment $existing, PaymentNotification $notification): array
    {
        AuditLog::create([
            'company_id' => $existing->company_id,
            'action' => 'Payment.duplicate_webhook',
            'auditable_type' => $existing->getMorphClass(),
            'auditable_id' => $existing->id,
            'context' => ['transaction_id' => $notification->transactionId, 'channel' => $notification->channel, 'amount' => $notification->amount],
            'ip_address' => request()?->ip(),
        ]);

        return ['status' => 'DUPLICATE', 'payment' => $existing];
    }

    /**
     * Match by loan reference (reference number or loan number) of a repayable loan — or of a WRITTEN-OFF loan, whose money
     * is then recorded as a recovery; otherwise by phone when the customer has exactly one repayable loan (never a written-off
     * one). Inferred: ambiguous phone matches go to suspense.
     */
    private function matchLoan(PaymentNotification $notification): ?Loan
    {
        $repayable = LoanStatus::values(...LoanStatus::repayable());

        if ($notification->reference !== null) {
            $loan = Loan::query()
                ->where(fn (Builder $query) => $query->where('reference_number', $notification->reference)->orWhere('loan_number', $notification->reference))
                ->whereIn('status', [...$repayable, LoanStatus::WrittenOff->value])
                ->orderByRaw('status = ? asc', [LoanStatus::WrittenOff->value])
                ->first();

            if ($loan !== null) {
                return $loan;
            }
        }

        $phone = $this->normalisePhone($notification->phone);
        if ($phone === null) {
            return null;
        }

        $local = '0'.substr($phone, 3);
        $loans = Loan::query()
            ->whereIn('status', $repayable)
            ->whereHas('customer', fn (Builder $query) => $query->whereIn('phone', [$phone, $local, '+'.$phone]))
            ->limit(2)
            ->get();

        return $loans->count() === 1 ? $loans->first() : null;
    }

    private function normalisePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '255') => $digits,
            strlen($digits) === 10 && str_starts_with($digits, '0') => '255'.substr($digits, 1),
            strlen($digits) === 9 => '255'.$digits,
            default => null,
        };
    }

    private function defaultCompanyId(): ?int
    {
        $configured = config('integrations.payments.company_id');
        if ($configured) {
            return (int) $configured;
        }

        return Company::query()->count() === 1 ? (int) Company::query()->value('id') : null;
    }

    private function receiptNumber(Payment $payment): string
    {
        return 'RC'.str_pad((string) $payment->id, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Text the customer the "rejesho" receipt worded by the company's payment_received template (SMS Centre → Templates);
     * nothing is sent when the template is switched off. A gateway failure never undoes the money movement ({@see SmsSender}).
     */
    private function notifyPayment(Payment $payment, Loan $loan, float $amount): void
    {
        $customer = $loan->customer;
        if ($customer === null || ! $customer->phone) {
            return;
        }

        $message = app(SmsTemplates::class)->render((int) $customer->company_id, SmsTemplates::PAYMENT_RECEIVED, [
            'name' => $customer->first_name,
            'amount' => money($amount),
            'receipt' => $payment->receipt_number ?? $payment->transaction_id,
            'balance' => money($this->loans->outstanding($loan->fresh())['total']),
            'loan_number' => $loan->reference_number ?? $loan->loan_number,
            'date' => now()->format('d/m/Y'),
            'company' => $customer->company?->name,
        ]);
        if ($message !== null) {
            app(SmsSender::class)->send((int) $customer->company_id, (string) $customer->phone, $message, 'payment', ['customer_id' => $customer->id, 'reference' => "payment:{$payment->id}"]);
        }
    }

    /**
     * System transaction ID for a Finance-entered payment (e.g. TX260917K3F9QD7A2B): never typed, unique per channel and provider.
     */
    public static function newTransactionId(): string
    {
        return 'TX'.now()->format('ymd').strtoupper(Str::random(10));
    }

    /**
     * System reference for a Finance-entered payment (e.g. PAY260917X7K2QD).
     */
    public static function newReference(): string
    {
        return 'PAY'.now()->format('ymd').strtoupper(Str::random(6));
    }
}
