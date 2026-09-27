<?php

namespace App\Services;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\AccountingPeriod;
use App\Models\ApprovalPolicy;
use App\Models\AuditLog;
use App\Models\CommissionAllocation;
use App\Models\Customer;
use App\Models\DividendDeclaration;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Models\LoanDisbursement;
use App\Models\LoanOffset;
use App\Models\LoanSchedule;
use App\Models\LoanTransaction;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\PenaltyPayment;
use App\Models\SmsLog;
use App\Models\WriteOff;
use App\Models\WriteOffRequest;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Customers\KycStatusCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LoanService
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly LoanCalculator $calculator,
    ) {}

    /**
     * @param  array{loan_category_id: int, group_id?: int|null, amount_applied: float, sessions: int, formula: string, fee_deduct: bool, reason: string}  $data
     */
    public function apply(Customer $customer, array $data, ?Employee $employee = null): Loan
    {
        $category = LoanCategory::findOrFail($data['loan_category_id']);
        $amount = (float) $data['amount_applied'];

        if ($amount < (float) $category->amount_from || $amount > (float) $category->amount_to) {
            throw ValidationException::withMessages(['amount_applied' => "Loan amount must be between {$category->level_label}"]);
        }

        if ($data['sessions'] < $category->repayment_from || $data['sessions'] > $category->repayment_to) {
            throw ValidationException::withMessages(['sessions' => "Number of repayments must be between {$category->repayment_from} - {$category->repayment_to}"]);
        }

        $loan = new Loan([
            'company_id' => $customer->company_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'loan_category_id' => $category->id,
            'group_id' => $data['group_id'] ?? null,
            'employee_id' => $employee?->id ?? $customer->employee_id,
            'loan_number' => $this->newLoanNumber(),
            'amount_applied' => $amount,
            'duration' => $category->duration,
            'sessions' => $data['sessions'],
            'formula' => $data['formula'],
            'fee_deduct' => $data['fee_deduct'],
            'reason' => $data['reason'],
            'interest_rate' => $category->interest_rate,
            'status' => LoanStatus::PendingManagerApproval,
        ]);

        $this->price($loan, $amount);
        $loan->save();

        return $loan;
    }

    /**
     * Recalculate the priced figures for a given principal.
     */
    public function price(Loan $loan, float $principal): void
    {
        $category = $loan->category ?? LoanCategory::findOrFail($loan->loan_category_id);
        // Specification §47: insurance is not part of the architecture, so a loan priced now carries none, whatever an
        // old category still says. Loans that already carry insurance keep it and are collected exactly as issued (§65).
        $insurance = 0.0;
        $figures = $this->calculator->calculate($loan->formula, $principal, (float) $loan->interest_rate, $loan->sessions, $insurance);

        $loan->fill([
            'interest_amount' => $figures['interest'],
            'total_payable' => $figures['total'],
            'restoration' => $figures['restoration'],
            'insurance' => $insurance,
            'loan_fee' => $category->feeFor($principal),
        ]);
    }

    /**
     * Deductions shown on the approval page ("Remain Loan Amount / Salary Advance / Penalty / Loan Fee").
     *
     * @return array{remain_loan: float, salary_advance: float, penalty: float, loan_fee: float, total: float, remain_cash: float}
     */
    public function deductions(Loan $loan): array
    {
        $customer = $loan->customer;
        $remainLoan = $customer->loans()
            ->whereKeyNot($loan->id)
            ->status(...LoanStatus::repayable())
            ->get()
            ->sum(fn (Loan $other): float => $other->remaining_amount);

        $salaryAdvance = (float) $customer->salaryAdvances()->where('status', 'active')->withSum('payments', 'amount')->get()
            ->sum(fn ($advance): float => $advance->remaining_amount);

        $penalty = (float) Penalty::where('customer_id', $customer->id)->where('is_waived', false)->get()
            ->sum(fn (Penalty $item): float => (float) $item->amount - (float) $item->paid_amount);

        $fee = $loan->fee_deduct ? (float) $loan->loan_fee : 0;
        $total = $remainLoan + $salaryAdvance + $penalty + $fee;
        $principal = (float) ($loan->amount_approved > 0 ? $loan->amount_approved : $loan->amount_applied);

        return [
            'remain_loan' => $remainLoan,
            'salary_advance' => $salaryAdvance,
            'penalty' => $penalty,
            'loan_fee' => $fee,
            'total' => $total,
            'remain_cash' => $principal - $total,
        ];
    }

    public function approve(Loan $loan, float $approvedAmount): void
    {
        if ($loan->customer->kyc_status !== KycStatusCalculator::COMPLETED) {
            throw ValidationException::withMessages(['loan' => "Please wait for the customer's KYC to be verified!"]);
        }

        DB::transaction(function () use ($loan, $approvedAmount): void {
            $loan->amount_approved = $approvedAmount;
            $this->price($loan, $approvedAmount);
            $loan->status = LoanStatus::AwaitingDisbursement;
            $loan->approved_at = now();
            $loan->withdrawal_code = (string) random_int(1000, 9999);
            $loan->save();

            $this->sendWithdrawalCode($loan);
        });
    }

    /**
     * The live system texts the customer a code that the teller must enter to cash out.
     * No SMS gateway could be observed, so the message is recorded in sms_logs.
     */
    public function sendWithdrawalCode(Loan $loan): void
    {
        SmsLog::create([
            'company_id' => $loan->company_id,
            'customer_id' => $loan->customer_id,
            'phone' => $loan->customer->phone,
            'message' => 'Mkopo wako wa TSH '.money($loan->amount_approved)." umeidhinishwa. Namba ya siri ya kutoa pesa ni {$loan->withdrawal_code}.",
        ]);
    }

    public function reject(Loan $loan): void
    {
        $loan->update(['status' => LoanStatus::Rejected]);
    }

    /**
     * Money reaches the customer (teller cash-out, or a successful Vodacom / other-channel disbursement):
     * posts the disbursement to the ledger and starts the repayment schedule.
     *
     * Posting: Dr LOAN RECEIVABLE (the customer's loan) / Cr the source account — the HQ PRINCIPAL A/C (the lending cash
     * the company floats to HQ, the default) or a company bank account. HQ runs no loan book of its own: the branch takes
     * the application and the receivable is tagged to it, but the money always leaves HQ. A deducted loan fee is income:
     * with the HQ cash source it lands in the branch LOAN FEE A/C (the branch earned it); with a bank source it never
     * leaves the bank, so the bank is debited back the fee. Never an expense or revenue for the principal itself.
     *
     * A fee that is NOT deducted (fee_deduct = false) is a memo only (rule 7): nothing is posted, and it never becomes part of
     * the principal, interest, penalty, repayment amount, schedules, outstanding balance or repayment allocation. An unpaid
     * fee is never income (rule 14, cash basis).
     *
     * @param  array{account: Account, branch?: int|null, bank?: int|null}|null  $source
     */
    public function withdraw(Loan $loan, CarbonImmutable $date, ?Employee $employee = null, string $description = 'CASH WITHDRAWALS', string $channel = 'cash', ?array $source = null): JournalEntry
    {
        if ($loan->status !== LoanStatus::AwaitingDisbursement) {
            throw ValidationException::withMessages(['withdrow' => 'Only disbursed loans can be withdrawn.']);
        }

        $source ??= ['account' => Account::Principal];
        if (! in_array($source['account'], [Account::Principal, Account::Bank], true) || ($source['account'] === Account::Bank && empty($source['bank']))) {
            throw new InvalidArgumentException('A loan can only be disbursed from the HQ PRINCIPAL A/C or a company bank account.');
        }

        return DB::transaction(function () use ($loan, $date, $employee, $description, $channel, $source): JournalEntry {
            $principal = (float) $loan->amount_approved;
            $fee = $loan->fee_deduct ? (float) $loan->loan_fee : 0;
            $feeRetainedIn = $source['account'] === Account::Bank
                ? ['account' => Account::Bank, 'bank' => $source['bank'] ?? null]
                : ['account' => Account::LoanFee, 'branch' => $loan->branch_id];

            $entry = $this->ledger->journal($loan->company_id, 'LOAN DISBURSEMENT '.$loan->loan_number, [
                ['account' => Account::LoanReceivable, 'branch' => $loan->branch_id, 'debit' => $principal],
                $source + ['credit' => $principal],
                $feeRetainedIn + ['debit' => $fee],
                ['account' => Account::FeeIncome, 'branch' => $loan->branch_id, 'credit' => $fee],
            ], $loan, $date, $loan->branch_id, $employee);

            LoanTransaction::create([
                'company_id' => $loan->company_id,
                'branch_id' => $loan->branch_id,
                'customer_id' => $loan->customer_id,
                'loan_id' => $loan->id,
                'employee_id' => $employee?->id,
                'type' => 'withdrawal',
                'description' => $description,
                'method' => strtoupper($channel),
                'amount' => $principal,
                'transaction_date' => $date->toDateString(),
            ]);

            $loan->schedules()->delete();
            $duration = $loan->duration;
            for ($session = 1; $session <= $loan->sessions; $session++) {
                $loan->schedules()->create([
                    'due_date' => $duration->addPeriods($date, $session)->toDateString(),
                    'amount' => $loan->restoration,
                ]);
            }

            $loan->update([
                'status' => LoanStatus::Active,
                'disbursement_channel' => $channel,
                'disbursed_at' => now(),
                'withdrawn_at' => $date->toDateString(),
                'end_date' => $duration->addPeriods($date, $loan->sessions)->toDateString(),
            ]);
            $loan->customer->update(['status' => 'open']);

            return $entry;
        });
    }

    /**
     * Repayment against a loan. Cash is allocated in the business-mandated order
     * Principal → Penalty → Interest (insurance, not covered by that rule, is collected last). A loan fee that was not
     * deducted at disbursement is never part of the allocation (rule 7).
     * Reserve is cut from the interest portion in real time and is not income (user decision D6): Dr INTEREST A/C (interest −
     * reserve) + Dr RESERVE A/C (reserve) / Cr INTEREST INCOME (interest − reserve) + Cr INTEREST RESERVE (reserve).
     * Penalty cash (rule 14, cash basis): Dr PENALTY A/C / Cr PENALTY INCOME. Only legacy penalties that were accrued when
     * charged (accrual_journal_entry_id set, stream P's short-lived D9) credit PENALTY RECEIVABLE instead, because their income
     * was already recognised — split per penalty, oldest first, exactly as settlePenalties() pays them.
     * Insurance cash (rule 15): Dr INSURANCE A/C / Cr INSURANCE RESERVE — never income, never distributable.
     * The principal returns to the HQ PRINCIPAL A/C it was lent from (no branch): a branch holds no lending money, only the
     * petty cash HQ sends it. The income accounts stay tagged to the branch, as a report of what that branch earned.
     *
     * Concurrency: the loan row is locked and the status, outstanding balance and allocation are recomputed from
     * committed data inside the transaction, so two repayments can never allocate the same balance twice. Callers
     * that already run a transaction (payment allocation, saving CLEAR, top-up settlement) simply nest into it.
     */
    public function deposit(Loan $loan, float $amount, CarbonImmutable $date, string $method = 'CASH', ?Employee $employee = null): LoanTransaction
    {
        return DB::transaction(function () use ($loan, $amount, $date, $method, $employee): LoanTransaction {
            $loan = Loan::whereKey($loan->id)->lockForUpdate()->with('company')->firstOrFail();

            if (! in_array($loan->status, LoanStatus::repayable(), true)) {
                throw ValidationException::withMessages(['depost' => 'This loan is not active.']);
            }

            $allocation = $this->allocate($loan, $amount, $date);
            if ($allocation['excess'] > 0.001) {
                throw ValidationException::withMessages(['depost' => 'Amount exceeds the outstanding balance of '.money($amount - $allocation['excess']).'.']);
            }

            $reserve = round($allocation['interest'] * (float) $loan->company->reserve_percent / 100, 2);
            $penaltySplit = $this->penaltySplit($loan, $allocation['penalty']);

            $transaction = LoanTransaction::create([
                'company_id' => $loan->company_id,
                'branch_id' => $loan->branch_id,
                'customer_id' => $loan->customer_id,
                'loan_id' => $loan->id,
                'employee_id' => $employee?->id,
                'type' => 'deposit',
                'description' => 'LOAN RETURN',
                'method' => $method,
                'amount' => $amount,
                'principal' => $allocation['principal'],
                'penalty' => $allocation['penalty'],
                'interest' => $allocation['interest'],
                'insurance' => $allocation['insurance'],
                'reserve' => $reserve,
                'transaction_date' => $date->toDateString(),
            ]);

            $branch = $loan->branch_id;
            $entry = $this->ledger->journal($loan->company_id, 'LOAN RETURN '.$loan->loan_number, [
                ['account' => Account::Principal, 'debit' => $allocation['principal']],
                ['account' => Account::LoanReceivable, 'branch' => $branch, 'credit' => $allocation['principal']],
                ['account' => Account::Penalty, 'branch' => $branch, 'debit' => $allocation['penalty']],
                ['account' => Account::PenaltyReceivable, 'branch' => $branch, 'credit' => $penaltySplit['accrued']],
                ['account' => Account::PenaltyIncome, 'branch' => $branch, 'credit' => $penaltySplit['legacy']],
                ['account' => Account::Interest, 'branch' => $branch, 'debit' => $allocation['interest'] - $reserve],
                ['account' => Account::Reserve, 'branch' => $branch, 'debit' => $reserve],
                ['account' => Account::InterestIncome, 'branch' => $branch, 'credit' => $allocation['interest'] - $reserve],
                ['account' => Account::InterestReserve, 'branch' => $branch, 'credit' => $reserve],
                ['account' => Account::Insurance, 'branch' => $branch, 'debit' => $allocation['insurance']],
                ['account' => Account::InsuranceReserve, 'branch' => $branch, 'credit' => $allocation['insurance']],
            ], $transaction, $date, $branch, $employee);
            $transaction->forceFill(['journal_entry_id' => $entry->id])->save();

            $this->settlePenalties($loan, $allocation['penalty'], $date, $transaction);
            $this->allocateToSchedules($loan, $allocation['principal'] + $allocation['interest'] + $allocation['insurance']);

            if ($this->outstanding($loan)['total'] <= 0.5) {
                $this->close($loan, $date);
            }

            return $transaction;
        });
    }

    /**
     * Outstanding balances per component (reversed repayments do not count). A loan fee is never part of it (rule 7).
     *
     * @return array{principal: float, penalty: float, interest: float, insurance: float, total: float}
     */
    public function outstanding(Loan $loan): array
    {
        $paid = $loan->transactions()->where('type', 'deposit')->whereNull('reversed_at')
            ->selectRaw('COALESCE(SUM(principal),0) p, COALESCE(SUM(interest),0) i, COALESCE(SUM(insurance),0) s')
            ->first();

        // A loan carried over from the old system keeps its printed Loan Amount in amount_approved and what the old
        // system had already collected in opening_paid_principal, so its outstanding principal opens at the printed
        // Remain Amount without a repayment of this system standing behind it (see the legacy import).
        $principal = max(0.0, round((float) $loan->amount_approved - (float) $loan->opening_paid_principal - (float) $paid->p, 2));
        $interest = max(0.0, round((float) $loan->interest_amount - (float) $paid->i, 2));
        $insurance = max(0.0, round((float) $loan->insurance - (float) $paid->s, 2));
        $penalty = max(0.0, round((float) Penalty::where('loan_id', $loan->id)->where('is_waived', false)->selectRaw('COALESCE(SUM(amount - paid_amount),0) v')->value('v'), 2));

        return [
            'principal' => $principal,
            'penalty' => $penalty,
            'interest' => $interest,
            'insurance' => $insurance,
            'total' => round($principal + $penalty + $interest + $insurance, 2),
        ];
    }

    /**
     * Split an amount across the outstanding components, in the order the specification mandates (§10):
     *
     *  1. the PRINCIPAL DUE on the instalments reached so far — not the whole loan's principal;
     *  2. PENALTY;
     *  3. the INTEREST DUE on those same instalments;
     *  4. anything beyond that expected amount reduces the OUTSTANDING PRINCIPAL outside the current instalment;
     *  5. then the rest of the interest, and (legacy loans only) the insurance new loans no longer carry, so that an early
     *     full settlement still clears everything.
     *
     * The spec's worked example: an instalment of principal 100,000, penalty 10,000 and interest 20,000 paid with 150,000
     * settles 100,000 + 10,000 + 20,000 and puts the last 20,000 against principal, so 120,000 of principal is paid.
     * Taking the whole loan's principal first (the earlier behaviour) would have swallowed all 150,000 and collected no
     * penalty or interest at all.
     *
     * @return array{principal: float, penalty: float, interest: float, insurance: float, excess: float}
     */
    public function allocate(Loan $loan, float $amount, ?CarbonImmutable $date = null): array
    {
        $remaining = round($amount, 2);
        $outstanding = $this->outstanding($loan);
        $reached = $this->instalmentsReached($loan, $date ?? CarbonImmutable::today());
        $take = function (float $available) use (&$remaining): float {
            $portion = round(min($remaining, max(0.0, $available)), 2);
            $remaining = round($remaining - $portion, 2);

            return $portion;
        };

        $principal = $take($loan->is_legacy_opening
            ? $this->legacyPrincipalDue($loan, $outstanding['principal'])
            : $this->dueNow((float) $loan->amount_approved, $outstanding['principal'], $reached, $loan));
        $penalty = $take($outstanding['penalty']);
        $interest = $take($this->dueNow((float) $loan->interest_amount, $outstanding['interest'], $reached, $loan));
        $principal += $take($outstanding['principal'] - $principal);
        $interest += $take($outstanding['interest'] - $interest);

        return [
            'principal' => round($principal, 2),
            'penalty' => $penalty,
            'interest' => round($interest, 2),
            'insurance' => $take($outstanding['insurance']),
            'excess' => max(0, $remaining),
        ];
    }

    /**
     * Instalments whose due date has arrived. Someone paying before the first due date is paying that first instalment
     * early, so one always counts: "the principal due for the relevant installment" (§10) is never nothing.
     */
    private function instalmentsReached(Loan $loan, CarbonImmutable $date): int
    {
        return max(1, $loan->schedules()->whereDate('due_date', '<=', $date->toDateString())->count());
    }

    /**
     * The part of a component (principal or interest) the borrower owes by now: its share of the instalments reached, less
     * what has already been paid. Instalments are level — restoration = (principal + interest + insurance) / sessions — so
     * one instalment carries the loan's total spread over its sessions.
     */
    private function dueNow(float $total, float $outstanding, int $reached, Loan $loan): float
    {
        $due = min($total, round($total / max(1, (int) $loan->sessions) * $reached, 2));

        return max(0.0, min($outstanding, round($due - ($total - $outstanding), 2)));
    }

    /**
     * The principal due now on a loan carried over from the old system. It has no schedule here, only the old system's
     * Collection (the amount due per instalment, kept in `instalment`), so one Collection is due first — or the whole
     * outstanding principal when the file printed no Collection. Its whole Remain Amount is principal (the old system
     * cannot split it), so the order stays Principal → Penalty → the rest.
     */
    private function legacyPrincipalDue(Loan $loan, float $outstanding): float
    {
        return (float) $loan->instalment > 0 ? min($outstanding, (float) $loan->instalment) : $outstanding;
    }

    /**
     * The penalty portion of a repayment split into legacy accrued penalties (PENALTY RECEIVABLE) and cash-basis penalties
     * (PENALTY INCOME), walking the open penalties in the same order and with the same portions as settlePenalties().
     *
     * @return array{accrued: float, legacy: float}
     */
    private function penaltySplit(Loan $loan, float $amount): array
    {
        $split = ['accrued' => 0.0, 'legacy' => 0.0];
        foreach (Penalty::where('loan_id', $loan->id)->where('is_waived', false)->whereColumn('paid_amount', '<', 'amount')->orderBy('penalty_date')->orderBy('id')->lockForUpdate()->get() as $penalty) {
            if ($amount <= 0) {
                break;
            }
            $portion = min($amount, (float) $penalty->amount - (float) $penalty->paid_amount);
            $split[$penalty->accrual_journal_entry_id !== null ? 'accrued' : 'legacy'] += $portion;
            $amount -= $portion;
        }

        return ['accrued' => round($split['accrued'], 2), 'legacy' => round($split['legacy'], 2)];
    }

    private function settlePenalties(Loan $loan, float $amount, CarbonImmutable $date, LoanTransaction $transaction): void
    {
        foreach (Penalty::where('loan_id', $loan->id)->where('is_waived', false)->whereColumn('paid_amount', '<', 'amount')->orderBy('penalty_date')->orderBy('id')->lockForUpdate()->get() as $penalty) {
            if ($amount <= 0) {
                break;
            }
            $portion = min($amount, (float) $penalty->amount - (float) $penalty->paid_amount);
            $penalty->payments()->create(['amount' => $portion, 'paid_on' => $date->toDateString(), 'loan_transaction_id' => $transaction->id]);
            $penalty->increment('paid_amount', $portion);
            $amount -= $portion;
        }
    }

    private function allocateToSchedules(Loan $loan, float $amount): void
    {
        foreach ($loan->schedules()->whereColumn('paid_amount', '<', 'amount')->get() as $schedule) {
            if ($amount <= 0) {
                break;
            }
            $portion = min($amount, (float) $schedule->amount - (float) $schedule->paid_amount);
            $schedule->increment('paid_amount', $portion);
            $amount -= $portion;
        }
    }

    /**
     * Overdue processing (Documents: "Cron Job POST /loans/overdue/process — missed payment → pending, apply penalty").
     * For every repayable loan: one penalty per missed instalment (company penalty setting: percentage of the unpaid
     * instalment or a fixed amount, only for products with penalty = YES), days past due from the oldest unpaid
     * instalment, ACTIVE ⇄ OVERDUE, and DEFAULT once the loan end date has passed with a balance (live behaviour).
     * Penalty basis (the overdue instalment) is inferred; the live calculation is server-side only.
     *
     * @return array{processed: int, penalties: int, penalty_amount: float, overdue: int, defaulted: int}
     */
    public function applyPenaltiesAndDefaults(CarbonImmutable $today): array
    {
        $summary = ['processed' => 0, 'penalties' => 0, 'penalty_amount' => 0.0, 'overdue' => 0, 'defaulted' => 0];

        Loan::query()->status(...LoanStatus::repayable())->with(['company', 'category', 'customer'])->each(function (Loan $loan) use ($today, &$summary): void {
            $summary['processed']++;
            $unpaid = $loan->schedules()->whereDate('due_date', '<', $today->toDateString())->whereColumn('paid_amount', '<', 'amount')->get();

            if ($loan->category?->has_penalty) {
                foreach ($unpaid as $schedule) {
                    $alreadyCharged = Penalty::where('loan_id', $loan->id)->whereDate('penalty_date', $schedule->due_date)->exists();
                    if ($alreadyCharged) {
                        continue;
                    }
                    $overdue = (float) $schedule->amount - (float) $schedule->paid_amount;
                    $value = (float) $loan->company->penalty_value;
                    $penalty = $loan->company->penalty_type === 'percentage' ? $overdue * $value / 100 : $value;
                    if ($penalty > 0) {
                        $this->chargePenalty($loan, round($penalty, 2), CarbonImmutable::parse($schedule->due_date));
                        $summary['penalties']++;
                        $summary['penalty_amount'] += round($penalty, 2);
                    }
                }
            }

            [$status, $daysPastDue] = $this->delinquency($loan, $unpaid, $today, $loan->status);

            if ($status === LoanStatus::Default && $loan->status !== LoanStatus::Default) {
                $summary['defaulted']++;
                $loan->customer->update(['status' => 'out']);
            }
            if ($status === LoanStatus::Overdue) {
                $summary['overdue']++;
            }

            if ($status !== $loan->status || $daysPastDue !== (int) $loan->days_past_due) {
                $loan->update(['status' => $status, 'days_past_due' => $daysPastDue]);
            }
        });

        return $summary;
    }

    /**
     * Days past due (from the oldest unpaid instalment due before today) and the repayable status they imply:
     * DEFAULT once the end date has passed with a balance (and DEFAULT is sticky), otherwise OVERDUE / ACTIVE.
     *
     * @param  Collection<int, LoanSchedule>  $unpaid  instalments due before today that are not fully paid
     * @return array{0: LoanStatus, 1: int}
     */
    private function delinquency(Loan $loan, Collection $unpaid, CarbonImmutable $today, LoanStatus $current): array
    {
        $oldestDue = $unpaid->min('due_date');
        $daysPastDue = $oldestDue !== null ? (int) CarbonImmutable::parse($oldestDue)->diffInDays($today) : 0;
        $status = $current;

        if ($loan->end_date !== null && $loan->end_date->lt($today) && $loan->remaining_amount > 0) {
            $status = LoanStatus::Default;
        } elseif ($status !== LoanStatus::Default) {
            $status = $daysPastDue > 0 ? LoanStatus::Overdue : LoanStatus::Active;
        }

        return [$status, $daysPastDue];
    }

    /**
     * Loan closure once fully paid (Documents: "LOAN CLOSURE → CLOSED", then "FREEZE PERIOD → cannot borrow").
     * closed_at is the ACTUAL FULL SETTLEMENT moment (nothing outstanding per outstanding(): principal, penalty,
     * interest and insurance); the early-settlement freeze decision is recorded in the same transaction.
     */
    public function close(Loan $loan, ?CarbonImmutable $date = null): void
    {
        DB::transaction(function () use ($loan): void {
            $loan->update([
                'status' => LoanStatus::Closed,
                'days_past_due' => 0,
                'closed_at' => $loan->closed_at ?? now(),
            ]);
            $this->recordSettlement($loan);

            if (! $loan->customer->loans()->status(...LoanStatus::repayable())->exists()) {
                $loan->customer->update(['status' => 'close']);
            }
        });
    }

    /**
     * Share of the loan repaid, as used by the top-up rule: repayments over principal + interest + insurance.
     */
    public function paidPercent(Loan $loan): float
    {
        $totalDue = (float) $loan->amount_approved + (float) $loan->interest_amount + (float) $loan->insurance;

        // What the old system had already collected on a loan carried over from it counts as repaid (never below nothing: an
        // old balance that carried a fee opens above its Loan Amount).
        return $totalDue > 0 ? round((max(0.0, (float) $loan->opening_paid_principal) + $loan->paid_amount) / $totalDue * 100, 2) : 0.0;
    }

    /**
     * Early full settlement freeze (loan category "Freeze Time (Days)"), decided once when the loan is fully settled.
     *
     *  - Early = settlement DATE (closed_at) before the maturity DATE (end_date = last schedule due date, else
     *    MAX(schedule due_date)), both in the app timezone. Settled on the maturity date or later is not early.
     *  - A loan closed by a top-up (a disbursed loan has topup_of_loan_id = this loan) is a refinance, never an early
     *    settlement — otherwise a top-up would freeze the very loan that paid it off.
     *  - Freeze window = [disbursed_at, disbursed_at + the category's freeze_time_days], the days snapshotted on the loan.
     *    It may already be over at settlement (then it is recorded as expired and blocks nothing). 0 days = no freeze.
     *  - Not early: every freeze field stays null.
     *
     * Idempotent: a loan whose decision exists (early_settlement not null) is never re-evaluated. Audited as
     * SETTLEMENT_FREEZE_DECISION.
     */
    public function recordSettlement(Loan $loan): void
    {
        $columns = ['early_settlement', 'expected_completion_date', 'freeze_started_at', 'freeze_days', 'frozen_until'];

        DB::transaction(function () use ($loan, $columns): void {
            $locked = Loan::whereKey($loan->id)->lockForUpdate()->with('category')->firstOrFail();

            if ($locked->early_settlement === null && $locked->closed_at !== null) {
                $decision = $this->settlementDecision($locked);
                $locked->update($decision['attributes']);
                app(LoanWorkflow::class)->record($locked, 'SETTLEMENT_FREEZE_DECISION', $locked->status, null, $decision['context']);
            }

            $loan->forceFill($locked->only($columns))->syncOriginalAttributes($columns);
        });
    }

    /**
     * @return array{attributes: array<string, mixed>, context: array<string, mixed>}
     */
    public function settlementDecision(Loan $loan): array
    {
        $settledAt = CarbonImmutable::parse($loan->closed_at);
        $maturity = $loan->end_date?->toDateString() ?? $loan->schedules()->max('due_date');
        $maturity = $maturity !== null ? substr((string) $maturity, 0, 10) : null;
        $byTopup = Loan::where('topup_of_loan_id', $loan->id)->whereNotNull('disbursed_at')->exists();
        $early = ! $byTopup && $maturity !== null && $settledAt->toDateString() < $maturity;
        $days = (int) ($loan->category?->freeze_time_days ?? 0);
        $start = $loan->disbursed_at !== null
            ? CarbonImmutable::parse($loan->disbursed_at)->startOfSecond()
            : ($loan->withdrawn_at !== null ? CarbonImmutable::parse($loan->withdrawn_at)->startOfDay() : null);
        $freezes = $early && $days > 0 && $start !== null;
        $until = $freezes ? $start->addDays($days) : null;

        $decision = match (true) {
            $byTopup => 'SETTLED_BY_TOPUP',
            $maturity === null => 'NO_MATURITY_DATE',
            ! $early => 'SETTLED_ON_OR_AFTER_MATURITY',
            $days === 0 => 'NO_FREEZE_TIME',
            $start === null => 'NO_DISBURSEMENT_DATE',
            $until->lte(CarbonImmutable::now()) => 'EARLY_SETTLEMENT_FREEZE_ALREADY_EXPIRED',
            default => 'EARLY_SETTLEMENT_FROZEN',
        };

        return [
            'attributes' => [
                'expected_completion_date' => $maturity,
                'early_settlement' => $early,
                'freeze_days' => $early ? $days : null,
                'freeze_started_at' => $freezes ? $start : null,
                'frozen_until' => $until,
            ],
            'context' => [
                'decision' => $decision,
                'disbursed_at' => $start?->toIso8601String(),
                'expected_completion_date' => $maturity,
                'settled_at' => $settledAt->toIso8601String(),
                'early_settlement' => $early,
                'freeze_days' => $early ? $days : null,
                'freeze_started_at' => $freezes ? $start->toIso8601String() : null,
                'frozen_until' => $until?->toIso8601String(),
            ],
        ];
    }

    /**
     * Charge a penalty (rule 14, cash basis — supersedes stream P's D9 accrual): only the penalty row is created, no journal.
     * The penalty becomes income when its cash is collected (repayment allocation or payPenalty()).
     */
    public function chargePenalty(Loan $loan, float $amount, CarbonImmutable $penaltyDate): Penalty
    {
        return Penalty::create([
            'company_id' => $loan->company_id,
            'branch_id' => $loan->branch_id,
            'customer_id' => $loan->customer_id,
            'loan_id' => $loan->id,
            'amount' => $amount,
            'penalty_date' => $penaltyDate->toDateString(),
        ]);
    }

    /**
     * Direct penalty payment. The penalty row is locked and its remaining balance re-checked inside the transaction.
     * Cash basis (rule 14): Dr PENALTY A/C / Cr PENALTY INCOME. A legacy penalty accrued when charged (accrual journal set)
     * credits PENALTY RECEIVABLE instead — its income was already recognised. A written-off loan's money is a recovery.
     *
     * @throws ValidationException
     */
    public function payPenalty(Penalty $penalty, float $amount, CarbonImmutable $date, ?Employee $employee = null): void
    {
        DB::transaction(function () use ($penalty, $amount, $date, $employee): void {
            $locked = Penalty::whereKey($penalty->id)->lockForUpdate()->firstOrFail();
            $remaining = round((float) $locked->amount - (float) $locked->paid_amount, 2);

            if ($locked->is_waived || $remaining <= 0) {
                throw ValidationException::withMessages(['penart_paid' => 'Penalty is already cleared']);
            }
            if ($locked->loan?->status === LoanStatus::WrittenOff) {
                throw ValidationException::withMessages(['penart_paid' => 'The loan has been written off; record the money as a recovery on the loan instead.']);
            }
            if ($amount > $remaining + 0.001) {
                throw ValidationException::withMessages(['penart_paid' => 'Amount is greater than penalty amount ('.money($remaining).')']);
            }

            $payment = $locked->payments()->create(['amount' => $amount, 'paid_on' => $date->toDateString()]);
            $locked->increment('paid_amount', $amount);
            $entry = $this->ledger->journal($locked->company_id, 'PENALTY', [
                ['account' => Account::Penalty, 'branch' => $locked->branch_id, 'debit' => $amount],
                ['account' => $locked->accrual_journal_entry_id !== null ? Account::PenaltyReceivable : Account::PenaltyIncome, 'branch' => $locked->branch_id, 'credit' => $amount],
            ], $locked, $date, $locked->branch_id, $employee, TransactionType::LoanPenaltyPayment);
            $payment->update(['journal_entry_id' => $entry->id]);
            $penalty->setRawAttributes($locked->fresh()->getAttributes(), true);
        });
    }

    /**
     * Waive (forgive) a penalty. The row is locked and re-checked. A cash-basis penalty was never recognised, so no journal is
     * posted (rule 14). Only a legacy penalty accrued when charged reverses the income of its unpaid remainder, dated today:
     * Dr PENALTY INCOME / Cr PENALTY RECEIVABLE, linked as waiver_journal_entry_id. Audited as Penalty.waived.
     *
     * @throws ValidationException
     */
    public function waivePenalty(Penalty $penalty, Employee $employee): Penalty
    {
        return DB::transaction(function () use ($penalty, $employee): Penalty {
            $locked = Penalty::whereKey($penalty->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_waived) {
                throw ValidationException::withMessages(['penalty' => 'This penalty has already been waived.']);
            }

            $before = $locked->only(['is_waived', 'amount', 'paid_amount']);
            $remaining = round((float) $locked->amount - (float) $locked->paid_amount, 2);
            $entry = null;
            if ($locked->accrual_journal_entry_id !== null && $remaining > 0.004) {
                $entry = $this->ledger->journal($locked->company_id, 'PENALTY WAIVER '.($locked->loan?->loan_number ?? $locked->loan_id), [
                    ['account' => Account::PenaltyIncome, 'branch' => $locked->branch_id, 'debit' => $remaining],
                    ['account' => Account::PenaltyReceivable, 'branch' => $locked->branch_id, 'credit' => $remaining],
                ], $locked, CarbonImmutable::today(), $locked->branch_id, $employee, TransactionType::PenaltyWaiver);
            }

            $locked->forceFill(['is_waived' => true, 'waiver_journal_entry_id' => $entry?->id])->save();

            AuditLog::create([
                'company_id' => $locked->company_id,
                'employee_id' => $employee->id,
                'action' => 'Penalty.waived',
                'auditable_type' => $locked->getMorphClass(),
                'auditable_id' => $locked->id,
                'before' => $before,
                'after' => ['is_waived' => true, 'waived_amount' => $remaining, 'waiver_journal_entry_id' => $entry?->id],
                'ip_address' => request()?->ip(),
            ]);

            return $locked;
        });
    }

    /**
     * Rule 6 (maker/checker): request the write-off of a repayable loan. Nothing is posted and the loan status is unchanged; a
     * different user holding loans.write_off approves ({@see approveWriteOff()}) or rejects it. One pending request per loan (the
     * loan row is locked). Audited as WriteOffRequest.requested and recorded on the loan workflow.
     *
     * @throws ValidationException
     */
    public function requestWriteOff(Loan $loan, Employee $requester, ?string $reason = null): WriteOffRequest
    {
        return DB::transaction(function () use ($loan, $requester, $reason): WriteOffRequest {
            $loan = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if (! in_array($loan->status, LoanStatus::repayable(), true)) {
                throw ValidationException::withMessages(['loan' => 'Only active, overdue or default loans can be written off']);
            }
            if (WriteOffRequest::where('loan_id', $loan->id)->where('status', WriteOffRequest::PENDING)->exists()) {
                throw ValidationException::withMessages(['loan' => 'A write-off request for this loan is already waiting for approval.']);
            }

            $request = WriteOffRequest::create([
                'company_id' => $loan->company_id,
                'branch_id' => $loan->branch_id,
                'loan_id' => $loan->id,
                'status' => WriteOffRequest::PENDING,
                'reason' => $reason,
                'requested_by' => $requester->id,
            ]);
            app(LoanWorkflow::class)->record($loan, 'WRITE_OFF_REQUESTED', $loan->status, $requester, [
                'write_off_request_id' => $request->id,
                'outstanding' => $this->outstanding($loan),
                'reason' => $reason,
            ]);

            return $request;
        });
    }

    /**
     * Approve a pending write-off request and post the write-off ({@see WriteOff()}), in one transaction with the request and
     * loan rows locked. The approver must not be the requester unless approvals.self_approve is explicitly granted.
     *
     * @throws ValidationException
     */
    public function approveWriteOff(WriteOffRequest $request, Employee $approver): WriteOff
    {
        return DB::transaction(function () use ($request, $approver): WriteOff {
            $locked = WriteOffRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== WriteOffRequest::PENDING) {
                throw ValidationException::withMessages(['request' => 'This write-off request has already been processed.']);
            }
            app(SegregationOfDuties::class)->assertCanApprove($locked->requested_by, $approver, 'write-off', workflow: ApprovalPolicy::WRITE_OFFS);

            $loan = Loan::findOrFail($locked->loan_id);
            $from = $loan->status;
            $writeOff = $this->writeOff($loan, $approver);
            $locked->update(['status' => WriteOffRequest::APPROVED, 'approved_by' => $approver->id, 'approved_at' => now(), 'write_off_id' => $writeOff->id]);

            app(LoanWorkflow::class)->record($loan->fresh(), 'WRITTEN_OFF', $from, $approver, [
                'write_off_request_id' => $locked->id,
                'amount' => (float) $writeOff->amount,
                'principal_amount' => (float) $writeOff->principal_amount,
                'penalty_amount' => (float) $writeOff->penalty_amount,
                'interest_amount' => (float) $writeOff->interest_amount,
                'insurance_amount' => (float) $writeOff->insurance_amount,
            ]);

            return $writeOff;
        });
    }

    /**
     * Reject a pending write-off request: nothing was posted; the row keeps who rejected it, when and why.
     *
     * @throws ValidationException
     */
    public function rejectWriteOff(WriteOffRequest $request, string $reason, Employee $employee): WriteOffRequest
    {
        return DB::transaction(function () use ($request, $reason, $employee): WriteOffRequest {
            $locked = WriteOffRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== WriteOffRequest::PENDING) {
                throw ValidationException::withMessages(['reason' => 'Only pending write-off requests can be rejected.']);
            }

            $locked->update(['status' => WriteOffRequest::REJECTED, 'rejected_by' => $employee->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            app(LoanWorkflow::class)->record($locked->loan, 'WRITE_OFF_REJECTED', $locked->loan->status, $employee, ['write_off_request_id' => $locked->id, 'reason' => $reason]);

            return $locked;
        });
    }

    /**
     * Pending write-off requests of a company (optionally limited to branches), for the Pending Approvals area: count, the
     * loans' current outstanding total and the rows.
     *
     * @param  list<int>|null  $branchIds  null = every branch
     * @return array{count: int, amount: float, rows: \Illuminate\Support\Collection<int, WriteOffRequest>}
     */
    public function pendingWriteOffRequests(int $companyId, ?array $branchIds = null): array
    {
        $rows = WriteOffRequest::query()
            ->where('company_id', $companyId)
            ->where('status', WriteOffRequest::PENDING)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->with(['loan.customer', 'branch', 'requester'])
            ->latest('id')
            ->get();

        return [
            'count' => $rows->count(),
            'amount' => round((float) $rows->sum(fn (WriteOffRequest $row): float => $row->loan !== null ? $this->outstanding($row->loan)['total'] : 0.0), 2),
            'rows' => $rows->toBase(),
        ];
    }

    /**
     * Post the write-off of a repayable loan: Dr WRITE-OFF EXPENSE / Cr LOAN RECEIVABLE for the outstanding principal, Cr PENALTY
     * RECEIVABLE only for unpaid legacy penalties that were accrued when charged (cash-basis penalties were never recognised,
     * rule 14, so they post nothing). A loan fee that was not deducted is never written off (rule 7: it was never owed).
     * WriteOff.amount keeps its historical meaning (total remaining: principal + penalty + interest + insurance);
     * principal_amount / penalty_amount / interest_amount / insurance_amount snapshot the outstanding components, which cap the
     * later recoveries per component ({@see LoanRecoveryService}). The loan row is locked and its status re-checked inside the
     * transaction. Called by {@see approveWriteOff()} (maker/checker); never directly from an endpoint.
     */
    public function writeOff(Loan $loan, ?Employee $employee = null): WriteOff
    {
        return DB::transaction(function () use ($loan, $employee): WriteOff {
            $loan = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if (! in_array($loan->status, LoanStatus::repayable(), true)) {
                throw ValidationException::withMessages(['loan' => 'Only active, overdue or default loans can be written off']);
            }

            $outstanding = $this->outstanding($loan);
            $accruedPenalties = round((float) Penalty::where('loan_id', $loan->id)->where('is_waived', false)->whereNotNull('accrual_journal_entry_id')
                ->lockForUpdate()->get()->sum(fn (Penalty $penalty): float => max(0.0, (float) $penalty->amount - (float) $penalty->paid_amount)), 2);
            $this->ledger->journal($loan->company_id, 'WRITE-OFF '.$loan->loan_number, [
                ['account' => Account::WriteOffExpense, 'branch' => $loan->branch_id, 'debit' => $outstanding['principal'] + $accruedPenalties],
                ['account' => Account::LoanReceivable, 'branch' => $loan->branch_id, 'credit' => $outstanding['principal']],
                ['account' => Account::PenaltyReceivable, 'branch' => $loan->branch_id, 'credit' => $accruedPenalties],
            ], $loan, null, $loan->branch_id, $employee);

            $writeOff = WriteOff::create([
                'loan_id' => $loan->id,
                'amount' => $outstanding['total'],
                'principal_amount' => $outstanding['principal'],
                'interest_amount' => $outstanding['interest'],
                'penalty_amount' => $outstanding['penalty'],
                'insurance_amount' => $outstanding['insurance'],
                'employee_id' => $employee?->id,
                'written_off_on' => now()->toDateString(),
            ]);
            $loan->update(['status' => LoanStatus::WrittenOff, 'days_past_due' => 0]);

            return $writeOff;
        });
    }

    /**
     * Why a loan repayment cannot be reversed now, or null when it can (spec §22, §26 Option A — dependent records
     * block the reversal, nothing is ever partially reversed). reverseRepayment() enforces exactly these checks.
     */
    public function repaymentReversalBlocker(LoanTransaction $deposit): ?string
    {
        $loan = $deposit->loan;
        $method = strtoupper((string) $deposit->method);

        if ($deposit->type !== 'deposit' || $loan === null) {
            return 'Only loan repayments can be reversed.';
        }
        if ($deposit->reversed_at !== null) {
            return 'This repayment has already been reversed.';
        }
        if ($loan->status === LoanStatus::WrittenOff) {
            return 'The loan has been written off; its repayments cannot be reversed.';
        }
        if ($method === 'TOPUP' || str_contains(strtoupper((string) $deposit->description), 'TOPUP')) {
            $topup = $this->topupLoanOf($deposit);

            return $topup !== null
                ? "This repayment is the settlement made by top-up loan {$topup->loan_number}; reverse that loan's disbursement and this settlement is reversed with it."
                : 'This repayment settled the loan from a top-up disbursement, but the top-up loan could not be found; it needs a manual correction.';
        }
        if ($method === 'SAVING') {
            return 'This repayment came from the customer\'s savings (CLEAR LOAN) and must be reversed from its origin, which is not supported.';
        }
        if (! in_array($loan->status, [...LoanStatus::repayable(), LoanStatus::Closed], true)) {
            return "Repayments cannot be reversed while the loan is {$loan->status->label()}.";
        }

        $later = LoanTransaction::query()
            ->where('loan_id', $loan->id)
            ->where('type', 'deposit')
            ->whereNull('reversed_at')
            ->whereKeyNot($deposit->id)
            ->where(fn ($query) => $query->where('id', '>', $deposit->id)->orWhereDate('transaction_date', '>', $deposit->transaction_date->toDateString()))
            ->latest('id')
            ->first();
        if ($later !== null) {
            return 'A later repayment of TZS '.money($later->amount).' on '.$later->transaction_date->toDateString().' exists; reverse repayments newest first.';
        }

        if ($loan->status === LoanStatus::Closed) {
            $newer = Loan::query()
                ->where('customer_id', $loan->customer_id)
                ->where('id', '>', $loan->id)
                ->whereNotIn('status', LoanStatus::values(LoanStatus::Rejected, LoanStatus::Cancelled))
                ->latest('id')
                ->first();
            if ($newer !== null) {
                return in_array($newer->status, LoanStatus::disbursed(), true)
                    ? "The loan was settled and the customer has borrowed again (loan {$newer->loan_number}); the settlement cannot be reversed."
                    : "The loan was settled and the customer has a newer loan application (loan {$newer->loan_number}) in progress; the settlement cannot be reversed.";
            }
        }

        $entry = $this->repaymentEntry($deposit);
        if ($entry === null) {
            return 'This repayment has no ledger posting to reverse.';
        }
        if ($entry->reversal()->exists()) {
            return "The journal entry {$entry->reference} of this repayment was already reversed directly in the ledger; the repayment needs a manual correction.";
        }
        if (($blocker = $this->distributedPeriodBlocker((int) $loan->company_id, CarbonImmutable::parse($entry->entry_date), 'repayment')) !== null) {
            return $blocker;
        }

        $allocation = $deposit->paymentAllocation()->whereNull('reversed_at')->with('payment')->first();
        if ($allocation?->payment !== null && in_array($allocation->payment->status, [PaymentStatus::Refunded, PaymentStatus::Rejected], true)) {
            return "The payment {$allocation->payment->receipt_number} of this repayment is {$allocation->payment->status->value}; the money cannot be returned to suspense.";
        }

        if ((float) $deposit->penalty > 0.004 && $this->penaltyPaymentsFor($deposit) === null) {
            return 'The penalty payments of this repayment cannot be matched reliably (several payments on the same day); reverse it manually.';
        }

        return null;
    }

    /**
     * Why the viewer cannot reverse this repayment now, or null when they can: the business blockers
     * ({@see repaymentReversalBlocker()}) and rule 6 — the employee who posted the repayment journal does not reverse it.
     */
    public function repaymentReverseBlockedReason(LoanTransaction $deposit, ?Employee $viewer): ?string
    {
        $reason = $this->repaymentReversalBlocker($deposit) ?? $this->pendingReversalReason($deposit);
        if ($reason === null && $viewer !== null) {
            $reason = app(SegregationOfDuties::class)->reverseBlockedReason($this->repaymentEntry($deposit), $viewer);
        }

        return $reason;
    }

    /**
     * Reverse a posted loan repayment (spec §22) in one transaction with the loan row locked:
     *  - Ledger::reverse() of the repayment entry — principal, penalty, interest (+ reserve) and insurance lines exactly as
     *    allocated, posted today; a repayment of a closed period without profit distributions is corrected in the current
     *    open period (spec §26 Option C);
     *  - the penalty payments of the repayment are removed and the penalties' paid amounts restored (snapshot in the audit log);     *  - instalment paid amounts are rebuilt from the remaining repayments (oldest due first);
     *  - a loan closed by this repayment reopens (ACTIVE / OVERDUE / DEFAULT for today) and its early-settlement freeze
     *    decision is cleared (SETTLEMENT_FREEZE_REVERSED);
     *  - the money returns to SUSPENSE, unallocated, so Finance can re-allocate or refund it: Dr BANK (the payment's bank
     *    A/C, or the bank clearing account) / Cr SUSPENSE. See PaymentService::returnReversedRepaymentToSuspense().
     *
     * @return array{transaction: LoanTransaction, reversal: JournalEntry, payment: Payment, closed_period: string|null}
     */
    public function reverseRepayment(LoanTransaction $deposit, string $reason, Employee $employee): array
    {
        return DB::transaction(function () use ($deposit, $reason, $employee): array {
            $loan = Loan::whereKey($deposit->loan_id)->lockForUpdate()->firstOrFail();
            $deposit = LoanTransaction::whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            $deposit->setRelation('loan', $loan);

            if (($blocker = $this->repaymentReversalBlocker($deposit)) !== null) {
                throw ValidationException::withMessages(['reason' => $blocker]);
            }

            $entry = $this->repaymentEntry($deposit);
            app(SegregationOfDuties::class)->assertCanReverse($entry, $employee);

            return $this->unwindRepayment($loan, $deposit, $entry, $reason, $employee, toSuspense: true);
        });
    }

    /**
     * The posting part of a repayment reversal, inside the caller's transaction with the loan and deposit rows locked and
     * every blocker already checked. With $toSuspense the money returns to SUSPENSE (a real repayment); without it nothing
     * is returned because the money never left the company (a top-up settlement, undone with its top-up disbursement).
     *
     * @return array{transaction: LoanTransaction, reversal: JournalEntry, payment: Payment|null, closed_period: string|null}
     */
    private function unwindRepayment(Loan $loan, LoanTransaction $deposit, JournalEntry $entry, string $reason, Employee $employee, bool $toSuspense): array
    {
        $closedPeriod = $this->closedPeriodOn((int) $loan->company_id, CarbonImmutable::parse($entry->entry_date));
        $penaltyPayments = $this->penaltyPaymentsFor($deposit) ?? new Collection;
        $from = $loan->status;
        $freeze = $loan->only(['closed_at', 'early_settlement', 'expected_completion_date', 'freeze_started_at', 'freeze_days', 'frozen_until']);

        $reversal = $this->ledger->reverse($entry->loadMissing('lines'), 'REPAYMENT REVERSED: '.$reason);

        foreach ($penaltyPayments as $penaltyPayment) {
            Penalty::whereKey($penaltyPayment->penalty_id)->lockForUpdate()->firstOrFail()->decrement('paid_amount', (float) $penaltyPayment->amount);
            $penaltyPayment->delete();
        }

        $deposit->update([
            'reversed_at' => now(),
            'reversed_by' => $employee->id,
            'reversal_reason' => $reason,
            'reversal_journal_entry_id' => $reversal->id,
        ]);
        $this->rebuildSchedules($loan);

        $payment = null;
        if ($toSuspense) {
            $payments = app(PaymentService::class);
            $allocation = $deposit->paymentAllocation()->whereNull('reversed_at')->first();
            $payment = $allocation !== null
                ? $payments->returnReversedRepaymentToSuspense($allocation, $employee)
                : $payments->holdReversedRepayment($deposit, $employee);
        }

        $this->reopenAfterReversal($loan, $from, $freeze, $employee);

        AuditLog::create([
            'company_id' => $loan->company_id,
            'employee_id' => $employee->id,
            'action' => 'LoanTransaction.reversed',
            'auditable_type' => $deposit->getMorphClass(),
            'auditable_id' => $deposit->id,
            'before' => ['reversed_at' => null],
            'after' => ['reversed_at' => $deposit->reversed_at?->toIso8601String(), 'reversal_journal_entry_id' => $reversal->id],
            'context' => [
                'reason' => $reason,
                'penalty_payments_removed' => $penaltyPayments->map(fn (PenaltyPayment $row): array => $row->only(['id', 'penalty_id', 'amount', 'paid_on']))->values()->all(),
                'payment_id' => $payment?->id,
            ],
            'ip_address' => request()?->ip(),
        ]);

        app(LoanWorkflow::class)->record($loan->fresh(), 'REPAYMENT_REVERSED', $from, $employee, [
            'transaction_id' => $deposit->id,
            'amount' => (float) $deposit->amount,
            'principal' => (float) $deposit->principal,
            'penalty' => (float) $deposit->penalty,
            'interest' => (float) $deposit->interest,
            'reserve' => (float) $deposit->reserve,
            'insurance' => (float) $deposit->insurance,
            'reason' => $reason,
            'journal_reference' => $entry->reference,
            'reversal_reference' => $reversal->reference,
            'returned_to_suspense' => $payment?->receipt_number,
            'closed_period_adjustment' => $closedPeriod?->period_start->format('Y-m'),
        ]);

        return ['transaction' => $deposit, 'reversal' => $reversal, 'payment' => $payment, 'closed_period' => $closedPeriod?->period_start->format('Y-m')];
    }

    /**
     * Why a loan disbursement cannot be reversed now, or null when it can (spec §21: never when downstream records
     * depend on it). reverseDisbursement() enforces exactly these checks.
     */
    public function disbursementReversalBlocker(Loan $loan): ?string
    {
        if (! in_array($loan->status, [LoanStatus::Active, LoanStatus::Overdue], true)) {
            return "Only an active or overdue loan can have its disbursement reversed (the loan is {$loan->status->label()}).";
        }
        if ($loan->transactions()->where('type', 'deposit')->whereNull('reversed_at')->exists()) {
            return 'The loan has repayments; reverse them first (newest first).';
        }
        if (Penalty::where('loan_id', $loan->id)->where('is_waived', false)->exists()) {
            return 'The loan has penalties; its disbursement cannot be reversed.';
        }
        if (Payment::where('loan_id', $loan->id)->whereIn('status', PaymentStatus::values(...PaymentStatus::awaitingFinance()))->exists()) {
            return 'The loan has branch receipts waiting for Finance verification or approval; resolve them first.';
        }
        if ($loan->topup_of_loan_id !== null && ($blocker = $this->topupSettlementBlocker($loan)) !== null) {
            return $blocker;
        }
        if (Loan::where('topup_of_loan_id', $loan->id)->whereNotIn('status', LoanStatus::values(LoanStatus::Rejected, LoanStatus::Cancelled))->exists()) {
            return 'Another loan is a top-up of this loan; its disbursement cannot be reversed.';
        }

        $entry = $this->disbursementEntry($loan);
        if ($entry === null) {
            return 'No disbursement journal entry was found for this loan.';
        }
        if ($entry->reversal()->exists()) {
            return "The disbursement journal entry {$entry->reference} was already reversed directly in the ledger; the loan needs a manual correction.";
        }
        if (($blocker = $this->distributedPeriodBlocker((int) $loan->company_id, CarbonImmutable::parse($entry->entry_date), 'disbursement')) !== null) {
            return $blocker;
        }

        foreach ($entry->lines()->with('account')->get() as $line) {
            if ($line->account?->key === Account::LoanFee && (float) $line->debit > 0) {
                $available = $this->ledger->balance($loan->company_id, Account::LoanFee, $line->account->branch_id);
                if ($available + 0.005 < (float) $line->debit) {
                    return 'The LOAN FEE A/C no longer holds the loan fee (available TZS '.money($available).', required TZS '.money($line->debit).').';
                }
            }
        }

        return null;
    }

    /**
     * Why the viewer cannot reverse this loan's disbursement now, or null when they can: the business blockers
     * ({@see disbursementReversalBlocker()}) and rule 6 — the employee who posted the disbursement journal does not reverse it.
     */
    public function disbursementReverseBlockedReason(Loan $loan, ?Employee $viewer): ?string
    {
        $reason = $this->disbursementReversalBlocker($loan) ?? $this->pendingReversalReason($loan);
        if ($reason === null && $viewer !== null) {
            $reason = app(SegregationOfDuties::class)->reverseBlockedReason($this->disbursementEntry($loan), $viewer);
        }

        return $reason;
    }

    /**
     * Reverse a loan disbursement (spec §21, §23) in one transaction with the loan row locked: Ledger::reverse() of the
     * disbursement entry (Dr the source PRINCIPAL A/C or bank / Cr LOAN RECEIVABLE, and the deducted fee Dr FEE INCOME /
     * Cr LOAN FEE A/C or bank), the disbursement batch and the withdrawal transaction are marked reversed (never deleted),
     * and the loan becomes CANCELLED with the reason. Schedules are kept (derived rows of a cancelled loan are excluded
     * wherever cancelled loans are). The money is assumed returned to the source account by the customer/provider.
     */
    public function reverseDisbursement(Loan $loan, string $reason, Employee $employee): Loan
    {
        return DB::transaction(function () use ($loan, $reason, $employee): Loan {
            $loan = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if (($blocker = $this->disbursementReversalBlocker($loan)) !== null) {
                throw ValidationException::withMessages(['reason' => $blocker]);
            }

            $entry = $this->disbursementEntry($loan);
            app(SegregationOfDuties::class)->assertCanReverse($entry, $employee);
            $from = $loan->status;
            $settlement = $this->unwindTopupSettlement($loan, $reason, $employee);
            $reversal = $this->ledger->reverse($entry->loadMissing('lines'), 'DISBURSEMENT REVERSED: '.$reason);
            $marks = ['reversed_at' => now(), 'reversed_by' => $employee->id, 'reversal_reason' => $reason, 'reversal_journal_entry_id' => $reversal->id];

            $disbursement = LoanDisbursement::where('loan_id', $loan->id)->where('journal_entry_id', $entry->id)->lockForUpdate()->first();
            $disbursement?->update($marks);
            $loan->transactions()->where('type', 'withdrawal')->whereNull('reversed_at')->update($marks);

            $loan->update(['status' => LoanStatus::Cancelled, 'decision_reason' => $reason, 'days_past_due' => 0]);

            $customer = $loan->customer;
            if (! $customer->loans()->status(...LoanStatus::repayable())->exists()) {
                $customer->update(['status' => $customer->loans()->status(LoanStatus::Closed)->exists() ? 'close' : 'pending']);
            }

            AuditLog::create([
                'company_id' => $loan->company_id,
                'employee_id' => $employee->id,
                'action' => 'Loan.disbursement_reversed',
                'auditable_type' => $loan->getMorphClass(),
                'auditable_id' => $loan->id,
                'before' => ['status' => $from->value],
                'after' => ['status' => LoanStatus::Cancelled->value, 'reversal_journal_entry_id' => $reversal->id],
                'context' => ['reason' => $reason, 'loan_disbursement_id' => $disbursement?->id, 'topup_settlement_reversed' => $settlement?->id],
                'ip_address' => request()?->ip(),
            ]);
            app(LoanWorkflow::class)->record($loan, 'DISBURSEMENT_REVERSED', $from, $employee, [
                'reason' => $reason,
                'batch_id' => $disbursement?->batch_id,
                'amount' => (float) $loan->amount_approved,
                'journal_reference' => $entry->reference,
                'reversal_reference' => $reversal->reference,
            ]);

            return $loan;
        });
    }

    /**
     * Why a direct penalty payment (Penalty → pay) cannot be reversed now, or null when it can. The penalty portion of a loan
     * repayment is reversed with the repayment, never on its own. reversePenaltyPayment() enforces exactly these checks.
     */
    public function penaltyPaymentReversalBlocker(PenaltyPayment $payment): ?string
    {
        $penalty = $payment->penalty;
        $loan = $penalty?->loan;

        if ($payment->reversed_at !== null) {
            return 'This penalty payment has already been reversed.';
        }
        if (! $payment->isDirect()) {
            return 'This penalty was paid as part of a loan repayment; reverse the repayment instead.';
        }
        if ($penalty === null) {
            return 'The penalty of this payment no longer exists.';
        }
        if ($penalty->is_waived) {
            return 'The penalty has been waived; its payments cannot be reversed.';
        }
        if ($loan !== null && ! in_array($loan->status, LoanStatus::repayable(), true)) {
            return "Penalty payments cannot be reversed while the loan is {$loan->status->label()}.";
        }

        $entry = $payment->journalEntry;
        if ($entry === null) {
            return 'This penalty payment has no linked ledger posting; it needs a manual correction.';
        }
        if ($entry->reversal()->exists()) {
            return "The journal entry {$entry->reference} of this penalty payment was already reversed directly in the ledger; it needs a manual correction.";
        }
        if (($blocker = $this->distributedPeriodBlocker((int) $penalty->company_id, CarbonImmutable::parse($entry->entry_date), 'penalty payment')) !== null) {
            return $blocker;
        }

        if ($loan !== null) {
            $later = LoanTransaction::query()
                ->where('loan_id', $loan->id)
                ->where('type', 'deposit')
                ->whereNull('reversed_at')
                ->where('penalty', '>', 0)
                ->where(fn ($query) => $query->whereDate('transaction_date', '>', $payment->paid_on->toDateString())
                    ->orWhere(fn ($sameDay) => $sameDay->whereDate('transaction_date', $payment->paid_on->toDateString())->where('created_at', '>', $payment->created_at)))
                ->exists();
            if ($later) {
                return 'A later loan repayment already paid penalty on this loan; reverse that repayment first (newest first).';
            }
        }

        $available = $this->ledger->balance($penalty->company_id, Account::Penalty, $penalty->branch_id);
        if ($available + 0.005 < (float) $payment->amount) {
            return 'The PENALTY A/C no longer holds this payment (available TZS '.money($available).', required TZS '.money($payment->amount).').';
        }

        return null;
    }

    /**
     * Reverse a direct penalty payment in one transaction with the penalty and payment rows locked: Ledger::reverse() of its
     * PENALTY entry posted today (Dr PENALTY INCOME, or PENALTY RECEIVABLE for a legacy accrued penalty / Cr PENALTY A/C),
     * the penalty's paid amount is restored and the payment row is marked reversed (kept, never deleted). Rule 6: the
     * employee who posted the payment does not reverse it. Audited as PenaltyPayment.reversed.
     *
     * @throws ValidationException
     */
    public function reversePenaltyPayment(PenaltyPayment $payment, string $reason, Employee $employee): PenaltyPayment
    {
        return DB::transaction(function () use ($payment, $reason, $employee): PenaltyPayment {
            $penalty = Penalty::whereKey($payment->penalty_id)->lockForUpdate()->firstOrFail();
            $payment = PenaltyPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $payment->setRelation('penalty', $penalty);

            if (($blocker = $this->penaltyPaymentReversalBlocker($payment)) !== null) {
                throw ValidationException::withMessages(['reason' => $blocker]);
            }

            $entry = $payment->journalEntry;
            app(SegregationOfDuties::class)->assertCanReverse($entry, $employee);
            $reversal = $this->ledger->reverse($entry->loadMissing('lines'), 'PENALTY PAYMENT REVERSED: '.$reason);

            $penalty->decrement('paid_amount', (float) $payment->amount);
            $payment->update([
                'reversed_at' => now(),
                'reversed_by' => $employee->id,
                'reversal_reason' => $reason,
                'reversal_journal_entry_id' => $reversal->id,
            ]);

            AuditLog::create([
                'company_id' => $penalty->company_id,
                'employee_id' => $employee->id,
                'action' => 'PenaltyPayment.reversed',
                'auditable_type' => $payment->getMorphClass(),
                'auditable_id' => $payment->id,
                'before' => ['reversed_at' => null, 'penalty_paid_amount' => round((float) $penalty->paid_amount + (float) $payment->amount, 2)],
                'after' => ['reversed_at' => $payment->reversed_at?->toIso8601String(), 'reversal_journal_entry_id' => $reversal->id, 'penalty_paid_amount' => (float) $penalty->fresh()->paid_amount],
                'context' => ['reason' => $reason, 'penalty_id' => $penalty->id, 'amount' => (float) $payment->amount, 'journal_reference' => $entry->reference, 'reversal_reference' => $reversal->reference],
                'ip_address' => request()?->ip(),
            ]);

            if ($penalty->loan !== null) {
                app(LoanWorkflow::class)->record($penalty->loan, 'PENALTY_PAYMENT_REVERSED', $penalty->loan->status, $employee, [
                    'penalty_payment_id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'reason' => $reason,
                    'journal_reference' => $entry->reference,
                    'reversal_reference' => $reversal->reference,
                ]);
            }

            return $payment;
        });
    }

    /**
     * The journal entry a repayment posted ({@see ReversalRequests} checks its poster when a reversal is requested).
     */
    public function repaymentJournalEntry(LoanTransaction $deposit): ?JournalEntry
    {
        return $this->repaymentEntry($deposit);
    }

    /**
     * The disbursement journal entry of a loan ({@see ReversalRequests} checks its poster when a reversal is requested).
     */
    public function disbursementJournalEntry(Loan $loan): ?JournalEntry
    {
        return $this->disbursementEntry($loan);
    }

    /**
     * Why the viewer cannot request the reversal of this direct penalty payment now, or null when they can: the business
     * blockers, a pending request, and rule 6 (the employee who posted the payment does not reverse it).
     */
    public function penaltyPaymentReverseBlockedReason(PenaltyPayment $payment, ?Employee $viewer): ?string
    {
        $reason = $this->penaltyPaymentReversalBlocker($payment) ?? $this->pendingReversalReason($payment);
        if ($reason === null && $viewer !== null) {
            $reason = app(SegregationOfDuties::class)->reverseBlockedReason($payment->journalEntry, $viewer);
        }

        return $reason;
    }

    /**
     * The top-up loan whose disbursement posted this settlement deposit (LoanOffset, else the audit row SETTLED_BY_TOPUP's loan).
     */
    public function topupLoanOf(LoanTransaction $deposit): ?Loan
    {
        $offset = LoanOffset::where('loan_transaction_id', $deposit->id)->first();

        return $offset !== null
            ? Loan::find($offset->new_loan_id)
            : Loan::where('topup_of_loan_id', $deposit->loan_id)->whereIn('status', LoanStatus::disbursed())->latest('id')->first();
    }

    /**
     * The settlement deposit a top-up loan posted on the loan it topped up, if it is still standing.
     */
    private function topupSettlement(Loan $topup): ?LoanTransaction
    {
        $offset = LoanOffset::where('new_loan_id', $topup->id)->whereNull('reversed_at')->first();
        $deposit = $offset !== null
            ? LoanTransaction::find($offset->loan_transaction_id)
            : LoanTransaction::where('loan_id', $topup->topup_of_loan_id)->where('type', 'deposit')->where('method', 'TOPUP')
                ->whereDate('transaction_date', CarbonImmutable::parse($topup->withdrawn_at ?? $topup->disbursed_at)->toDateString())->latest('id')->first();

        return $deposit?->reversed_at === null ? $deposit : null;
    }

    /**
     * Why a top-up's settlement of the previous loan cannot be undone with the top-up disbursement, or null when it can:
     * the previous loan must be exactly as the settlement left it (no later repayment, no newer loan than the top-up, no
     * write-off) and the settlement's own ledger posting must be reversible.
     */
    private function topupSettlementBlocker(Loan $topup): ?string
    {
        $deposit = $this->topupSettlement($topup);
        if ($deposit === null) {
            return null;
        }
        $previous = Loan::find($deposit->loan_id);
        if ($previous === null) {
            return 'The loan settled by this top-up no longer exists; it needs a manual correction.';
        }
        if (! in_array($previous->status, [...LoanStatus::repayable(), LoanStatus::Closed], true)) {
            return "The loan {$previous->loan_number} settled by this top-up is {$previous->status->label()}; the top-up cannot be reversed.";
        }
        $later = LoanTransaction::where('loan_id', $previous->id)->where('type', 'deposit')->whereNull('reversed_at')->where('id', '>', $deposit->id)->exists();
        if ($later) {
            return "The loan {$previous->loan_number} settled by this top-up has later repayments; reverse them first.";
        }
        $newer = Loan::where('customer_id', $previous->customer_id)->where('id', '>', $previous->id)->whereKeyNot($topup->id)
            ->whereNotIn('status', LoanStatus::values(LoanStatus::Rejected, LoanStatus::Cancelled))->first();
        if ($newer !== null) {
            return "The customer has another loan ({$newer->loan_number}) after the one this top-up settled; the top-up cannot be reversed.";
        }
        $entry = $this->repaymentEntry($deposit);
        if ($entry === null) {
            return "The top-up settlement of loan {$previous->loan_number} has no ledger posting to reverse.";
        }
        if ($entry->reversal()->exists()) {
            return "The journal entry {$entry->reference} of the top-up settlement was already reversed directly in the ledger; it needs a manual correction.";
        }
        if (($blocker = $this->distributedPeriodBlocker((int) $previous->company_id, CarbonImmutable::parse($entry->entry_date), 'top-up settlement')) !== null) {
            return $blocker;
        }
        if ((float) $deposit->penalty > 0.004 && $this->penaltyPaymentsFor($deposit) === null) {
            return 'The penalty payments of the top-up settlement cannot be matched reliably; reverse it manually.';
        }

        return null;
    }

    /**
     * Reversing a top-up's disbursement first undoes the settlement it posted on the previous loan (exact mirror of its
     * journal, penalties and instalments restored, the loan reopened) and marks the offset reversed. No money goes to
     * SUSPENSE: the settlement was paid out of the top-up itself, whose disbursement is reversed right after.
     */
    private function unwindTopupSettlement(Loan $topup, string $reason, Employee $employee): ?LoanTransaction
    {
        if ($topup->topup_of_loan_id === null || ($deposit = $this->topupSettlement($topup)) === null) {
            return null;
        }

        $previous = Loan::whereKey($deposit->loan_id)->lockForUpdate()->firstOrFail();
        $deposit = LoanTransaction::whereKey($deposit->id)->lockForUpdate()->firstOrFail();
        $deposit->setRelation('loan', $previous);
        $entry = $this->repaymentEntry($deposit);
        $this->unwindRepayment($previous, $deposit, $entry, 'TOP-UP '.$topup->loan_number.' REVERSED: '.$reason, $employee, toSuspense: false);
        LoanOffset::where('new_loan_id', $topup->id)->whereNull('reversed_at')->update(['reversed_at' => now()]);
        app(LoanWorkflow::class)->record($previous->fresh(), 'TOPUP_SETTLEMENT_REVERSED', LoanStatus::Closed, $employee, [
            'amount' => (float) $deposit->amount,
            'top_up_loan' => $topup->loan_number,
            'reason' => $reason,
        ]);

        return $deposit;
    }

    private function pendingReversalReason(Model $subject): ?string
    {
        return app(ReversalRequests::class)->pendingFor($subject) !== null ? ReversalRequests::PENDING_MESSAGE : null;
    }

    /**
     * The journal entry a repayment posted (linked, or found by its source for rows posted before the link existed).
     */
    private function repaymentEntry(LoanTransaction $deposit): ?JournalEntry
    {
        return $deposit->journal_entry_id !== null
            ? JournalEntry::find($deposit->journal_entry_id)
            : JournalEntry::where('source_type', $deposit->getMorphClass())->where('source_id', $deposit->id)->whereNull('reversal_of_id')->oldest('id')->first();
    }

    /**
     * The disbursement journal entry: the successful batch's entry, else (loans posted before batches kept it) the
     * loan-sourced LOAN DISBURSEMENT entry.
     */
    private function disbursementEntry(Loan $loan): ?JournalEntry
    {
        $entryId = LoanDisbursement::where('loan_id', $loan->id)->where('status', LoanDisbursement::SUCCESS)->whereNotNull('journal_entry_id')->latest('id')->value('journal_entry_id');

        return $entryId !== null
            ? JournalEntry::find($entryId)
            : JournalEntry::where('source_type', $loan->getMorphClass())->where('source_id', $loan->id)->whereNull('reversal_of_id')->where('description', 'like', 'LOAN DISBURSEMENT%')->oldest('id')->first();
    }

    public function closedPeriodOn(int $companyId, CarbonImmutable $date): ?AccountingPeriod
    {
        return AccountingPeriod::query()
            ->where('company_id', $companyId)
            ->closed()
            ->whereDate('period_start', '<=', $date->toDateString())
            ->whereDate('period_end', '>=', $date->toDateString())
            ->first();
    }

    /**
     * A transaction of a closed period whose profit was already distributed (dividend declaration, commission calculated or
     * commission allocation) cannot be reversed: that would leave commission / reinvestment / dividend based on the old profit
     * (spec §25).
     */
    public function distributedPeriodBlocker(int $companyId, CarbonImmutable $date, string $what): ?string
    {
        $period = $this->closedPeriodOn($companyId, $date);
        if ($period === null) {
            return null;
        }

        $distributions = array_filter([
            DividendDeclaration::where('company_id', $companyId)->whereBetween('period', [$period->period_start->toDateString(), $period->period_end->toDateString()])->exists() ? 'dividend declaration' : null,
            CommissionAllocation::where('accounting_period_id', $period->id)->exists() ? 'commission allocation' : ($period->commission_calculated_at !== null ? 'commission calculation' : null),
        ]);

        return $distributions === []
            ? null
            : "Profit for the closed period {$period->period_start->format('Y-m')} has already been distributed (".implode(' and ', $distributions)."); this {$what} cannot be reversed.";
    }

    /**
     * The penalty payments a repayment created: linked rows, or — for repayments posted before the link existed — the
     * unlinked penalty payments of the loan on the repayment date when they add up to its penalty portion and no other
     * repayment that day paid penalty. Null when they cannot be matched reliably.
     *
     * @return Collection<int, PenaltyPayment>|null
     */
    private function penaltyPaymentsFor(LoanTransaction $deposit): ?Collection
    {
        $penalty = round((float) $deposit->penalty, 2);
        $linked = PenaltyPayment::where('loan_transaction_id', $deposit->id)->get();

        if ($linked->isNotEmpty()) {
            return abs(round((float) $linked->sum('amount'), 2) - $penalty) < 0.005 ? $linked : null;
        }
        if ($penalty <= 0.004) {
            return new Collection;
        }

        $sameDay = LoanTransaction::where('loan_id', $deposit->loan_id)->where('type', 'deposit')->whereNull('reversed_at')->whereKeyNot($deposit->id)
            ->whereDate('transaction_date', $deposit->transaction_date->toDateString())->where('penalty', '>', 0)->exists();
        if ($sameDay) {
            return null;
        }

        $candidates = PenaltyPayment::whereNull('loan_transaction_id')
            ->whereNull('journal_entry_id')
            ->standing()
            ->whereDate('paid_on', $deposit->transaction_date->toDateString())
            ->whereHas('penalty', fn ($query) => $query->where('loan_id', $deposit->loan_id))
            ->get();

        return abs(round((float) $candidates->sum('amount'), 2) - $penalty) < 0.005 ? $candidates : null;
    }

    /**
     * Instalment paid amounts rebuilt deterministically from the non-reversed repayments' principal + interest +
     * insurance, filling instalments oldest due first (the order deposit() fills them).
     */
    private function rebuildSchedules(Loan $loan): void
    {
        $paid = round((float) $loan->transactions()->where('type', 'deposit')->whereNull('reversed_at')
            ->selectRaw('COALESCE(SUM(principal + interest + insurance), 0) v')->value('v'), 2);

        foreach ($loan->schedules()->orderBy('id')->lockForUpdate()->get() as $schedule) {
            $portion = round(max(0.0, min($paid, (float) $schedule->amount)), 2);
            if (abs((float) $schedule->paid_amount - $portion) > 0.004) {
                $schedule->update(['paid_amount' => $portion]);
            }
            $paid = round($paid - $portion, 2);
        }
    }

    /**
     * After a repayment reversal: a loan closed by it reopens with today's arrears status and loses its settlement
     * freeze decision; otherwise its arrears status and days past due are refreshed.
     *
     * @param  array<string, mixed>  $freeze  the settlement / freeze fields before the reversal
     */
    private function reopenAfterReversal(Loan $loan, LoanStatus $from, array $freeze, Employee $employee): void
    {
        $today = CarbonImmutable::today();
        $unpaid = $loan->schedules()->whereDate('due_date', '<', $today->toDateString())->whereColumn('paid_amount', '<', 'amount')->get();
        $wasClosed = $from === LoanStatus::Closed;
        [$status, $daysPastDue] = $this->delinquency($loan, $unpaid, $today, $wasClosed ? LoanStatus::Active : $from);

        $attributes = ['status' => $status, 'days_past_due' => $daysPastDue];
        if ($wasClosed) {
            $attributes += ['closed_at' => null, 'early_settlement' => null, 'expected_completion_date' => null, 'freeze_started_at' => null, 'freeze_days' => null, 'frozen_until' => null];
        }
        $loan->update($attributes);

        if ($wasClosed) {
            app(LoanWorkflow::class)->record($loan, 'SETTLEMENT_FREEZE_REVERSED', $from, $employee, collect($freeze)
                ->map(fn (mixed $value): mixed => $value instanceof CarbonInterface ? $value->toIso8601String() : $value)
                ->all());
        }
        if ($wasClosed || ($status === LoanStatus::Default && $from !== LoanStatus::Default)) {
            $loan->customer->update(['status' => $status === LoanStatus::Default ? 'out' : 'open']);
        }
    }

    public function newLoanNumber(): string
    {
        do {
            $number = (string) random_int(10000000000000, 99999999999999);
        } while (Loan::where('loan_number', $number)->exists());

        return $number;
    }
}
