<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Loans\Actions\SettleDisbursementAction;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Repayments\Actions\RecordCashPaymentAction;
use App\Domain\Repayments\Actions\RecordRepaymentAction;
use App\Domain\Repayments\Enums\PaymentChannel;
use App\Domain\Repayments\Services\PaymentReferenceGenerator;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * Brings the ledger to life: capital, disbursements and repayments.
 *
 * Everything here goes through the SAME engine the API uses — LedgerService,
 * SettleDisbursementAction, RecordRepaymentAction. Nothing writes a journal
 * line directly. A seeder with its own posting logic would produce a ledger
 * that balances in the seed and nowhere else, and would hide exactly the bugs
 * these seeded numbers are supposed to expose.
 */
final class LedgerActivitySeeder extends Seeder
{
    public function run(): void
    {
        $finance = User::query()->where('phone', '0754000003')->first();
        $teller = User::query()->where('phone', '0754000010')->first();

        if ($finance === null) {
            return;
        }

        $this->injectCapital($finance);
        $this->disburseReadyLoans($finance);
        $this->collectRepayments($finance, $teller);
    }

    /**
     * §5: "Capital injection: Dr Bank/Cash · Cr Capital Account."
     *
     * Without this the loan book would be funded from nowhere and the balance
     * sheet would open with negative equity.
     */
    private function injectCapital(User $finance): void
    {
        $ledger = app(LedgerService::class);
        $accounts = app(AccountResolver::class);

        $amount = Money::of('200000000.00');

        $ledger->post(
            description: 'Founding capital contribution',
            sourceType: JournalSourceType::CapitalInjection,
            sourceId: null,
            lines: [
                JournalLine::debit((int) $accounts->defaultBankAccount()->getKey(), $amount),
                JournalLine::credit($accounts->systemId(SystemAccountCode::Capital), $amount),
            ],
            postedBy: $finance,
            entryDate: Date::now()->subMonths(6)->toImmutable(),
        );
    }

    /**
     * Settles every prepared batch — the provider callback Phase 5 could not
     * implement without a ledger.
     */
    private function disburseReadyLoans(User $finance): void
    {
        $settle = app(SettleDisbursementAction::class);

        $loans = Loan::query()
            ->with('disbursementBatches')
            ->where('status', LoanStatus::AwaitingDisbursement)
            ->get();

        foreach ($loans as $loan) {
            $batch = $loan->disbursementBatches->last();

            if ($batch === null) {
                continue;
            }

            $settle->succeed($batch, $finance);
        }
    }

    /**
     * Repays part of each active loan, alternating between a provider payment
     * and a teller cash entry so both intake channels appear in the ledger.
     *
     * Amounts are deliberately partial: a book where every loan is settled has
     * no arrears, no outstanding balance and nothing for the collections
     * screens to show.
     */
    private function collectRepayments(User $finance, ?User $teller): void
    {
        $repayments = app(RecordRepaymentAction::class);
        $cash = app(RecordCashPaymentAction::class);
        $references = app(PaymentReferenceGenerator::class);

        $loans = Loan::query()
            ->with(['schedules', 'branch'])
            ->where('status', LoanStatus::Active)
            ->orderBy('id')
            ->get();

        foreach ($loans as $index => $loan) {
            // Every third loan is left untouched, so the book has loans that
            // have never paid as well as loans part-way through.
            if ($index % 3 === 2) {
                continue;
            }

            // Roughly the first two installments' worth.
            $target = $loan->schedules->take(2);

            if ($target->isEmpty()) {
                continue;
            }

            $amount = Money::sum($target->map(fn ($s): Money => $s->totalDue()));

            if (! $amount->isPositive()) {
                continue;
            }

            if ($index % 2 === 0 && $teller !== null) {
                $cash->handle($loan, $amount, $teller);

                continue;
            }

            $payment = Payment::query()->create([
                'payment_reference' => $references->next(),
                'loan_id' => $loan->getKey(),
                'customer_id' => $loan->customer_id,
                'amount' => $amount->toDecimalString(),
                'channel' => PaymentChannel::MobileMoney,
                'transaction_id' => sprintf('TXN%08d', 10_000 + $index),
                'status' => \App\Domain\Repayments\Enums\PaymentStatus::Received,
                'branch_id' => $loan->branch_id,
                'received_at' => Date::now()->subDays(5),
                'created_by' => $finance->getKey(),
            ]);

            $repayments->applyToLoan($payment, $loan, viaSuspense: false, actor: $finance);
        }
    }
}
