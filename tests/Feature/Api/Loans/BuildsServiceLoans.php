<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Services\Accounting\LedgerIntegrity;
use App\Services\Ledger;
use App\Services\LoanService;
use Carbon\CarbonImmutable;

/**
 * Loans built through the real LoanService (apply → approve → withdraw): 100,000 principal, 30 % SIMPLE interest (30,000),
 * and a loan fee (5,000 by default), deducted or not.
 *
 * New loans carry no insurance any more (specification §47), but loans issued before that rule still do and are collected
 * exactly as issued, so these fixtures keep a 1,000 insurance on the loan by default — a legacy insured loan — to keep that
 * path covered. Pass `legacyInsurance: 0` for a loan priced under the current rule.
 */
trait BuildsServiceLoans
{
    protected function serviceLoan(Employee $admin, bool $feeDeducted = true, float $amount = 100000, ?int $branchId = null, float $fee = 5000, float $legacyInsurance = 1000): Loan
    {
        $branchId ??= $admin->branch_id;
        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $branchId]);
        $category = LoanCategory::factory()->create(['company_id' => $admin->company_id, 'fee_value' => $fee]);
        $ledger = app(Ledger::class);
        // The lending cash lives in the HQ PRINCIPAL A/C (no branch): a customer applies at a branch, but HQ always pays.
        if ($ledger->balance($admin->company_id, Account::Principal) < $amount) {
            $ledger->openingBalance($admin->company_id, Account::Principal, $amount, 'FLOAT');
        }

        $loans = app(LoanService::class);
        $loan = $loans->apply($customer, ['loan_category_id' => $category->id, 'amount_applied' => $amount, 'sessions' => 1, 'formula' => 'SIMPLE', 'fee_deduct' => $feeDeducted, 'reason' => 'BIASHARA']);
        $loans->approve($loan, $amount);
        $loans->withdraw($loan->fresh(), CarbonImmutable::today(), $admin);

        if ($legacyInsurance > 0) {
            $loan->refresh();
            $perSession = round($legacyInsurance / max(1, (int) $loan->sessions), 2);
            $loan->forceFill(['insurance' => $legacyInsurance, 'restoration' => round((float) $loan->restoration + $perSession, 2)])->save();
            $loan->schedules()->increment('amount', $perSession);
        }

        return $loan->fresh();
    }

    /**
     * Balances of the given accounts for one branch. PRINCIPAL A/C is the exception: the lending cash is company level
     * (HQ, no branch), so it is always read without a branch — a branch holds no lending money, only its PETTY CASH A/C.
     * SUSPENSE likewise: pending / unverified receipts live in one central HQ account (specification §11).
     *
     * @param  list<Account>  $accounts
     * @return array<string, float>
     */
    protected function balances(Employee $admin, array $accounts, ?int $branchId = null): array
    {
        return collect($accounts)->mapWithKeys(fn (Account $account): array => [
            $account->value => app(Ledger::class)->balance($admin->company_id, $account, in_array($account, [Account::Principal, Account::Suspense], true) ? null : ($branchId ?? $admin->branch_id)),
        ])->all();
    }

    /**
     * @return list<array{0: string, 1: float, 2: float}> [account key, debit, credit] ordered by line id
     */
    protected function entryLines(int $entryId): array
    {
        return JournalEntry::with('lines.account')->findOrFail($entryId)->lines->sortBy('id')
            ->map(fn ($line): array => [$line->account->key->value, (float) $line->debit, (float) $line->credit])->values()->all();
    }

    protected function assertIntegrityPasses(Employee $admin): void
    {
        $checks = collect(app(LedgerIntegrity::class)->run($admin->company_id)['checks']);
        $problems = $checks->whereIn('status', ['fail', 'warn'])->reject(fn (array $check): bool => $check['key'] === 'capital' && $check['status'] === 'warn');

        $this->assertSame([], $problems->values()->all());
    }
}
