<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Treasury\Enums\BankTransferKind;
use App\Domain\Treasury\Enums\BankTransferStatus;
use App\Domain\Treasury\Exceptions\BankMovementInvalidException;
use App\Enums\ActiveStatus;
use App\Enums\AuditAction;
use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\Branch;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Moves money out of a bank account — the two Transfer Balance screens.
 *
 * Unlike the queues elsewhere in this module, a transfer **applies
 * immediately** and is recorded `completed`. That follows the legacy screens,
 * which show no approval step for either: both are one person moving the
 * company's own money between its own accounts, which is the same reasoning
 * that lets company-to-branch float apply without a second signature.
 *
 * The posting, for a branch transfer of X with a charge of F:
 *
 *     Dr  destination branch teller cash    X
 *     Dr  6150 Bank Charges                 F
 *     Cr  source bank account               X + F
 *
 * The charge is a separate debit rather than being netted off the amount, so
 * the destination receives what was sent and the cost of sending it stays
 * visible as a cost. Netting would hide the fee inside a transfer and leave
 * nothing on the P&L to show what banking actually costs.
 *
 * A salary-advance transfer is the same shape with another bank account in
 * place of the till.
 */
final class RequestBankTransferAction
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountResolver $chart,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(
        BankTransferKind $kind,
        BankAccount $from,
        ?BankAccount $toAccount,
        ?Branch $toBranch,
        string $amount,
        string $chargeFee,
        string $reason,
        ?string $description,
        ?string $reference,
        ?string $transferredOn,
        User $actor,
    ): BankTransfer {
        $this->guardDestination($kind, $from, $toAccount, $toBranch);

        $money = Money::of($amount);
        $fee = Money::of($chargeFee);
        $total = $money->add($fee);

        return DB::transaction(function () use (
            $kind, $from, $toAccount, $toBranch, $amount, $chargeFee,
            $reason, $description, $reference, $transferredOn, $actor, $money, $fee, $total
        ): BankTransfer {
            $sourceChart = $from->loadMissing('chartAccount.balances')->chartAccount;

            if ($sourceChart === null || $sourceChart->status !== ActiveStatus::Active) {
                throw BankMovementInvalidException::inactiveAccount($from->account_name);
            }

            $available = $sourceChart->cachedBalance();

            // The amount and the charge both leave, so both must be there.
            if ($available->lessThan($total)) {
                throw BankMovementInvalidException::insufficientFunds(
                    $from->account_name,
                    $available->toDecimalString(),
                    $total->toDecimalString(),
                );
            }

            $destinationId = $kind->targetsBranch()
                ? (int) $this->chart->tellerCash($toBranch)->getKey()
                : (int) $this->destinationChart($toAccount)->getKey();

            $branchId = $toBranch?->getKey();

            $lines = [
                JournalLine::debit($destinationId, $money, branchId: $branchId),
                JournalLine::credit((int) $sourceChart->getKey(), $total, branchId: $branchId),
            ];

            // Only when there is one — a zero-amount line is not a line, and
            // LedgerService rejects it.
            if ($fee->isPositive()) {
                array_splice($lines, 1, 0, [
                    JournalLine::debit($this->chart->systemId(SystemAccountCode::BankCharges), $fee),
                ]);
            }

            $entry = $this->ledger->post(
                sprintf('Transfer — %s', $reason),
                JournalSourceType::Transfer,
                null,
                $lines,
                $actor,
                $transferredOn === null ? null : Date::parse($transferredOn)->toImmutable(),
            );

            $transfer = BankTransfer::query()->create([
                // The legacy form lets the user supply their own reference;
                // when they do not, one is allocated.
                'reference' => $reference ?? $this->nextReference(),
                'kind' => $kind,
                'from_account_id' => $from->getKey(),
                'to_account_id' => $toAccount?->getKey(),
                'to_branch_id' => $toBranch?->getKey(),
                'amount' => $amount,
                'charge_fee' => $chargeFee,
                'reason' => $reason,
                'description' => $description,
                'requested_by' => $actor->getKey(),
                // Applied on the spot, so it is never pending.
                'status' => BankTransferStatus::Completed,
                'journal_entry_id' => $entry->getKey(),
                'transferred_on' => $transferredOn ?? Date::now()->toDateString(),
            ]);

            $this->audit->log(
                AuditAction::BankTransferCompleted,
                $transfer,
                after: [
                    'reference' => $transfer->reference,
                    'kind' => $kind->value,
                    'amount' => $transfer->amount,
                    'charge_fee' => $transfer->charge_fee,
                    'journal_entry_id' => $entry->getKey(),
                ],
                actor: $actor,
            );

            return $transfer->load(BankTransfer::LIST_RELATIONS);
        });
    }

    /** Each kind has one destination, and it is not the source. */
    private function guardDestination(
        BankTransferKind $kind,
        BankAccount $from,
        ?BankAccount $toAccount,
        ?Branch $toBranch,
    ): void {
        if ($kind->targetsBranch()) {
            if ($toBranch === null || $toAccount !== null) {
                throw BankMovementInvalidException::wrongDestination(
                    'A branch transfer names the branch the money goes to, and nothing else.',
                );
            }

            return;
        }

        if ($toAccount === null || $toBranch !== null) {
            throw BankMovementInvalidException::wrongDestination(
                'A salary advance transfer names the account the money goes to, and nothing else.',
            );
        }

        if ($toAccount->getKey() === $from->getKey()) {
            throw BankMovementInvalidException::sameAccount();
        }
    }

    private function destinationChart(BankAccount $account): \App\Models\ChartOfAccount
    {
        $chart = $account->chartAccount;

        if ($chart === null || $chart->status !== ActiveStatus::Active) {
            throw BankMovementInvalidException::inactiveAccount($account->account_name);
        }

        return $chart;
    }

    /** TRF-0000001, from the highest allocated one. */
    private function nextReference(): string
    {
        $highest = (int) DB::table('bank_transfers')
            ->where('reference', 'like', 'TRF-%')
            ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)), 0) AS seq')
            ->value('seq');

        return 'TRF-'.str_pad((string) ($highest + 1), 7, '0', STR_PAD_LEFT);
    }
}
