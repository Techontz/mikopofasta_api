<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\ReserveUtilisationStatus;
use App\Domain\Accounting\Exceptions\ReserveException;
use App\Domain\Accounting\Services\ReserveBalanceReader;
use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Enums\AuditAction;
use App\Models\ReserveUtilisation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Admin decides on a proposed use of the Reserve — Decision Register D1.
 *
 * "Reserve transfers require Admin approval. Every reserve movement must be
 * fully audited."
 *
 * Approval is where the reserve is released:
 *
 *     Dr  3000 Reserve    (the reservation is given up)
 *       Cr  1000 Capital  (the equity it was protecting)
 *
 * Every purpose posts identically, which is the client's own model: "inaweza
 * kurudi kwa njia ya mtaji" — it returns BY WAY OF CAPITAL. Reserve is a
 * control account, not a bank balance; nothing was ever moved into it, so
 * nothing can be moved out. A release un-reserves equity, and the branch or
 * department is then funded from capital and spends through the ordinary
 * expense path. See ReserveUtilisationPurpose for the full reasoning.
 *
 * The balance guard runs here rather than at request time. Two proposals can
 * both be raised against a balance that only covers one, and the arithmetic
 * that matters is the arithmetic on the day of release.
 */
final class DecideReserveUtilisationAction
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountResolver $accounts,
        private readonly ReserveBalanceReader $reserve,
        private readonly AuditLogger $audit,
    ) {}

    public function approve(ReserveUtilisation $request, User $approver): ReserveUtilisation
    {
        $this->assertPending($request);

        $amount = $request->amountMoney();
        $available = $this->reserve->available();

        if ($amount->greaterThan($available)) {
            throw ReserveException::insufficient($amount, $available);
        }

        return DB::transaction(function () use ($request, $approver, $amount): ReserveUtilisation {
            $entry = $this->ledger->post(
                sprintf('Reserve utilisation %s — %s', $request->reference, $request->purpose->label()),
                JournalSourceType::ReserveUtilisation,
                (int) $request->getKey(),
                [
                    JournalLine::debit(
                        $this->accounts->systemId(SystemAccountCode::Reserve),
                        $amount,
                    ),
                    /*
                     * No branch dimension, even when the release names one.
                     * Capital is company-wide and D1 keeps the Reserve with
                     * Headquarters; tagging the credit to a branch would read
                     * as that branch holding equity of its own. The branch is
                     * on the request row, which is where the reporting looks.
                     */
                    JournalLine::credit(
                        $this->accounts->systemId(SystemAccountCode::Capital),
                        $amount,
                    ),
                ],
                $approver,
            );

            $before = ['status' => $request->status->value];

            $request->update([
                'status' => ReserveUtilisationStatus::Approved,
                'approved_by' => $approver->getKey(),
                'approved_at' => Date::now(),
                'journal_entry_id' => $entry->getKey(),
            ]);

            $this->audit->log(
                AuditAction::ReserveUtilisationApproved,
                $request,
                before: $before,
                after: [
                    'status' => ReserveUtilisationStatus::Approved->value,
                    'amount' => $request->amount,
                    'purpose' => $request->purpose->value,
                    'target_branch_id' => $request->target_branch_id,
                    'journal_entry_id' => $entry->getKey(),
                ],
                actor: $approver,
            );

            return $request->load(['requester', 'approver', 'targetBranch']);
        });
    }

    public function reject(ReserveUtilisation $request, string $reason, User $approver): ReserveUtilisation
    {
        $this->assertPending($request);

        return DB::transaction(function () use ($request, $reason, $approver): ReserveUtilisation {
            $before = ['status' => $request->status->value];

            $request->update([
                'status' => ReserveUtilisationStatus::Rejected,
                'approved_by' => $approver->getKey(),
                'approved_at' => Date::now(),
                'decision_reason' => $reason,
            ]);

            $this->audit->log(
                AuditAction::ReserveUtilisationRejected,
                $request,
                before: $before,
                after: [
                    'status' => ReserveUtilisationStatus::Rejected->value,
                    'decision_reason' => $reason,
                ],
                actor: $approver,
            );

            return $request->load(['requester', 'approver', 'targetBranch']);
        });
    }

    private function assertPending(ReserveUtilisation $request): void
    {
        if (! $request->status->isPending()) {
            throw ReserveException::notPending($request->reference);
        }
    }
}
