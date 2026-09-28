<?php

namespace App\Services;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\Saving;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Insurance savings (menu "Insurance"). Money is held in the branch HQ saving account and owed to the
 * customer through the Savings Deposits liability:
 *  - deposit: Dr HQ Saving / Cr Savings Deposits;
 *  - withdrawal (TAKEN or CLEAR LOAN): Dr Savings Deposits / Cr HQ Saving. A CLEAR LOAN withdrawal is then
 *    paid into the customer's active loan through LoanService::deposit (allocation Principal → Penalty → Interest).
 *  - corrections: reversal entries only.
 */
class SavingService
{
    public const TAKEN = 'TAKEN';

    public const CLEAR = 'CLEAR';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly LoanService $loans,
    ) {}

    public function balance(Customer $customer): float
    {
        return round((float) $customer->savings()->whereNull('reversed_at')->sum(DB::raw("CASE WHEN type = 'deposit' THEN amount ELSE -amount END")), 2);
    }

    public function deposit(Customer $customer, float $amount, ?Employee $employee = null, ?string $description = null): Saving
    {
        return DB::transaction(function () use ($customer, $amount, $employee, $description): Saving {
            $saving = Saving::create([
                'company_id' => $customer->company_id,
                'branch_id' => $customer->branch_id,
                'customer_id' => $customer->id,
                'employee_id' => $employee?->id,
                'type' => 'deposit',
                'description' => $description ?: 'SAVING DEPOSIT',
                'amount' => $amount,
                'transaction_date' => today(),
            ]);

            $this->ledger->transfer(
                $customer->company_id,
                ['account' => Account::SavingsDeposits, 'branch' => $customer->branch_id],
                ['account' => Account::HqSaving, 'branch' => $customer->branch_id],
                $amount,
                $saving->description,
                $saving,
            );

            return $saving;
        });
    }

    /**
     * @param  'TAKEN'|'CLEAR'  $withdrawalType
     */
    public function withdraw(Customer $customer, float $amount, string $withdrawalType, ?Employee $employee = null): Saving
    {
        return DB::transaction(function () use ($customer, $amount, $withdrawalType, $employee): Saving {
            Customer::whereKey($customer->id)->lockForUpdate()->first();

            if ($amount > $this->balance($customer) + 0.001) {
                throw ValidationException::withMessages(['with_sav' => 'Insufficient saving balance']);
            }

            $loan = null;
            if ($withdrawalType === self::CLEAR) {
                $loan = $customer->loans()->status(...LoanStatus::repayable())->oldest('id')->first();
                if ($loan === null) {
                    throw ValidationException::withMessages(['action' => 'Customer has no active loan to clear']);
                }
            }

            $saving = Saving::create([
                'company_id' => $customer->company_id,
                'branch_id' => $customer->branch_id,
                'customer_id' => $customer->id,
                'loan_id' => $loan?->id,
                'employee_id' => $employee?->id,
                'type' => 'withdrawal',
                'withdrawal_type' => $withdrawalType,
                'description' => $withdrawalType === self::CLEAR ? 'SAVING CLEAR LOAN' : 'SAVING TAKEN',
                'amount' => $amount,
                'transaction_date' => today(),
            ]);

            $this->ledger->transfer(
                $customer->company_id,
                ['account' => Account::HqSaving, 'branch' => $customer->branch_id],
                ['account' => Account::SavingsDeposits, 'branch' => $customer->branch_id],
                $amount,
                $saving->description,
                $saving,
            );

            if ($loan instanceof Loan) {
                $this->loans->deposit($loan, $amount, CarbonImmutable::today(), 'SAVING', $employee);
            }

            return $saving;
        });
    }

    /**
     * Reverse a saving transaction. A CLEAR LOAN withdrawal already applied to a loan cannot be reversed
     * here — the loan repayment must be reversed from the loan/accounting module first.
     */
    public function reverse(Saving $saving, string $reason, Employee $employee): void
    {
        if ($saving->reversed_at !== null) {
            throw ValidationException::withMessages(['reason' => 'Transaction is already reversed']);
        }
        if ($saving->withdrawal_type === self::CLEAR) {
            throw ValidationException::withMessages(['reason' => 'A saving used to clear a loan cannot be reversed here']);
        }
        if ($saving->type === 'deposit' && $this->balance($saving->customer) < (float) $saving->amount - 0.001) {
            throw ValidationException::withMessages(['reason' => 'Saving balance is lower than the deposit to reverse']);
        }

        DB::transaction(function () use ($saving, $reason, $employee): void {
            JournalEntry::query()
                ->where('source_type', $saving->getMorphClass())
                ->where('source_id', $saving->id)
                ->whereNull('reversal_of_id')
                ->whereDoesntHave('reversal')
                ->with('lines')
                ->get()
                ->each(fn (JournalEntry $entry) => $this->ledger->reverse($entry, $reason));

            $saving->update(['reversed_at' => now(), 'reversal_reason' => $reason, 'reversed_by' => $employee->id]);
        });
    }
}
