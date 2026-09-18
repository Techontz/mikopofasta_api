<?php

declare(strict_types=1);

namespace App\Domain\CustomerAdvances\Actions;

use App\Domain\CustomerAdvances\Enums\CustomerAdvanceStatus;
use App\Domain\CustomerAdvances\Exceptions\CustomerAdvanceStateException;
use App\Domain\CustomerAdvances\Services\CustomerAdvanceCalculator;
use App\Domain\CustomerAdvances\Services\CustomerAdvanceFunding;
use App\Domain\CustomerAdvances\Services\CustomerAdvancePostingBuilder;
use App\Domain\CustomerAdvances\Services\CustomerAdvanceReferenceGenerator;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Repayments\Enums\PaymentChannel;
use App\Enums\AuditAction;
use App\Models\ChartOfAccount;
use App\Models\CustomerAdvance;
use App\Models\CustomerAdvancePayment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A customer paying against their salary advance.
 *
 * This is the half of the client's rule that decides what a month earned:
 *
 * > "sehemu ya mtaji itarudi Principal Operation na faida itaingia Income
 * > Operation" — 200,000 of a 220,000 payment is capital going back, 20,000 is
 * > profit.
 *
 * Everything happens in ONE transaction. A payment whose register row committed
 * but whose ledger entry did not would be money the customer has been credited
 * for and the books have never seen — and the dashboard summary would then
 * disagree with the accounts it claims to summarise.
 *
 * Nothing here writes to a "Salary Advance account", because there is none. The
 * cash lands in the account the advance was funded from, the receivable falls,
 * and the profit is recognised. The Salary Advance Payments figure the
 * dashboard shows is read back from these rows.
 */
final class CollectCustomerAdvanceAction
{
    public function __construct(
        private readonly CustomerAdvanceCalculator $calculator,
        private readonly CustomerAdvancePostingBuilder $postings,
        private readonly CustomerAdvanceFunding $funding,
        private readonly CustomerAdvanceReferenceGenerator $references,
        private readonly AccountResolver $accounts,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function collect(
        CustomerAdvance $advance,
        Money $amount,
        PaymentChannel $channel,
        User $actor,
        ?CarbonImmutable $paidAt = null,
        ?string $note = null,
    ): CustomerAdvancePayment {
        if ($advance->status !== CustomerAdvanceStatus::Disbursed) {
            throw CustomerAdvanceStateException::notCollectable();
        }

        return DB::transaction(function () use ($advance, $amount, $channel, $actor, $paidAt, $note): CustomerAdvancePayment {
            /*
             * Re-read under a lock before deciding anything. Two tellers
             * collecting against the same advance at the same moment would
             * otherwise both split against the same stale balance, and the
             * second would over-recover it.
             */
            $advance = CustomerAdvance::query()
                ->whereKey($advance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $advance->loadMissing(['branch', 'customer']);

            $outstanding = $this->calculator->outstanding($advance);

            if ($amount->greaterThan($outstanding)) {
                throw CustomerAdvanceStateException::overpayment($outstanding->toDecimalString());
            }

            $split = $this->calculator->split($advance, $amount);
            $cashAccount = $this->cashAccountFor($advance, $channel);

            $entry = $this->ledger->post(
                description: sprintf(
                    'Salary advance payment — %s (%s)',
                    $advance->reference,
                    $advance->customer?->fullName() ?? 'customer',
                ),
                sourceType: JournalSourceType::CustomerAdvancePayment,
                sourceId: (int) $advance->getKey(),
                lines: $this->postings->buildPayment($advance, $split, $cashAccount),
                postedBy: $actor,
                entryDate: $paidAt,
            );

            $payment = CustomerAdvancePayment::query()->create([
                'reference' => $this->references->nextPayment(),
                'customer_advance_id' => $advance->getKey(),
                'branch_id' => $advance->branch_id,
                'amount' => $amount->toDecimalString(),
                'principal_portion' => $split->principal->toDecimalString(),
                'interest_portion' => $split->interest->toDecimalString(),
                'fee_portion' => $split->fee->toDecimalString(),
                'channel' => $channel->value,
                'note' => $note,
                'paid_at' => $paidAt ?? Date::now(),
                'received_by' => $actor->getKey(),
                'journal_entry_id' => $entry->getKey(),
            ]);

            $repaid = $advance->repaidMoney()->add($amount);
            $settled = ! $this->calculator->totalRepayable($advance)->greaterThan($repaid);

            $advance->update([
                'amount_repaid' => $repaid->toDecimalString(),
                'principal_repaid' => $advance->principalRepaidMoney()->add($split->principal)->toDecimalString(),
                'interest_repaid' => $advance->interestRepaidMoney()->add($split->interest)->toDecimalString(),
                'fee_repaid' => $advance->feeRepaidMoney()->add($split->fee)->toDecimalString(),
                'status' => $settled ? CustomerAdvanceStatus::Settled : $advance->status,
                'settled_at' => $settled ? ($paidAt ?? Date::now()) : null,
            ]);

            $this->audit->log(
                AuditAction::CustomerAdvanceRepaid,
                $advance,
                after: [
                    'payment_reference' => $payment->reference,
                    'amount' => $amount->toDecimalString(),
                    'principal' => $split->principal->toDecimalString(),
                    'profit' => $split->profit()->toDecimalString(),
                    'journal_entry' => $entry->entry_number,
                    'received_into' => $cashAccount->name,
                ],
                actor: $actor,
            );

            if ($settled) {
                $this->audit->log(
                    AuditAction::CustomerAdvanceSettled,
                    $advance,
                    after: ['total_repaid' => $repaid->toDecimalString()],
                    actor: $actor,
                );
            }

            Log::channel('operations')->info('Customer salary advance payment', [
                'advance' => $advance->reference,
                'payment' => $payment->reference,
                'amount' => $amount->toDecimalString(),
                'principal' => $split->principal->toDecimalString(),
                'profit' => $split->profit()->toDecimalString(),
                'journal_entry' => $entry->entry_number,
                'settled' => $settled,
            ]);

            return $payment->load('advance');
        });
    }

    /**
     * Where the collection lands.
     *
     * The account the advance was funded from, so the capital returns to the
     * operational money it left. Cash collected at the counter is the exception
     * the channel decides: it goes into that branch's till, because that is
     * where the notes physically are.
     */
    private function cashAccountFor(CustomerAdvance $advance, PaymentChannel $channel): ChartOfAccount
    {
        if ($channel->isCash() && $advance->branch !== null) {
            return $this->accounts->tellerCash($advance->branch);
        }

        $funding = $advance->funding_account_id === null
            ? null
            : ChartOfAccount::query()->find($advance->funding_account_id);

        if ($funding === null) {
            return $this->accounts->cashAccountFor($channel->isCash(), $advance->branch);
        }

        return $this->funding->forCollection($funding, $channel->isCash(), $advance->branch);
    }
}
