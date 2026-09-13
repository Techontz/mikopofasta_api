<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Repayments\Exceptions\DuplicateTransactionException;
use App\Domain\Treasury\DTOs\CapitalContributionData;
use App\Enums\ActiveStatus;
use App\Enums\AuditAction;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CapitalContribution;
use App\Models\ChartOfAccount;
use App\Models\CompanyProfile;
use App\Models\Shareholder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records capital paid in by a shareholder (POST /capital-contributions).
 *
 * Posts double-entry through LedgerService, the only code path allowed to
 * write journal lines (§5):
 *
 *     Dr  head-office cash or bank   (where the money landed)
 *     Cr  1000 Capital Account       (what the company now owes its owners)
 *
 * Cash lands in the head-office till. A cheque or transfer lands in the
 * registered company account the officer names, or the default bank account
 * when none is named — AccountResolver::cashAccountFor(), the same helper a
 * cash repayment uses.
 *
 * Traceability: the contribution carries a unique reference, the account it
 * landed in, the shareholder's own source account and who recorded it; the
 * entry's source_id points back at the contribution. Moving the money on to an
 * operational account afterwards is a Transfer (bank transfer or float), never
 * another capital injection — so it cannot be counted as capital twice.
 */
final class RecordCapitalAction
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountResolver $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Shareholder $shareholder, CapitalContributionData $data, User $actor): CapitalContribution
    {
        return DB::transaction(function () use ($shareholder, $data, $actor): CapitalContribution {
            $amount = Money::of($data->amount);
            $headOffice = $this->headOffice();

            [$cashAccount, $bankAccount] = $this->receivingAccount($data, $headOffice);

            /*
             * The row first, then the entry, then the link — so the entry can
             * carry the contribution's id as its source and the two point at
             * each other. All inside one transaction: a contribution never
             * commits without its entry, nor an entry without its contribution.
             *
             * The request already refuses a reference that exists; the catch is
             * for the race that check cannot see — two identical submissions
             * landing together. The UNIQUE index decides, and the loser is told
             * it is a duplicate rather than handed a 500.
             */
            try {
                $contribution = CapitalContribution::query()->create([
                    // Null lets the model allocate CAP-{id}.
                    'reference' => $data->reference,
                    'shareholder_id' => $shareholder->id,
                    'amount' => $data->amount,
                    'pay_method' => $data->payMethod,
                    'receipt_no' => $data->receiptNo,
                    'cheque_no' => $data->chequeNo,
                    'bank_account_id' => $bankAccount?->getKey(),
                    'received_account_id' => $cashAccount->getKey(),
                    'source_account_name' => $data->sourceAccountName,
                    'source_account_number' => $data->sourceAccountNumber,
                    'created_by' => $actor->getKey(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateTransactionException((string) $data->reference);
            }

            $entry = $this->ledger->post(
                sprintf('Capital from %s — %s', $shareholder->full_name, $contribution->reference),
                JournalSourceType::CapitalInjection,
                (int) $contribution->id,
                [
                    JournalLine::debit($cashAccount->id, $amount, branchId: $headOffice?->id),
                    JournalLine::credit($this->accounts->systemId(SystemAccountCode::Capital), $amount),
                ],
                $actor,
            );

            $contribution->journal_entry_id = $entry->id;
            $contribution->save();

            $this->audit->log(
                AuditAction::CapitalRecorded,
                $contribution,
                after: [
                    'reference' => $contribution->reference,
                    'shareholder_id' => $shareholder->id,
                    'amount' => $contribution->amount,
                    'pay_method' => $data->payMethod->value,
                    'received_account_id' => $cashAccount->id,
                    'bank_account_id' => $bankAccount?->getKey(),
                    'journal_entry_id' => $entry->id,
                ],
                actor: $actor,
            );

            return $contribution->load(['shareholder', 'receivedAccount', 'journalEntry', 'recorder']);
        });
    }

    /**
     * Where the money landed.
     *
     * Cash: the head-office till, as before. Otherwise the registered company
     * account the officer named — which must be one that may receive money —
     * or, when none was named, the default bank account.
     *
     * @return array{0: ChartOfAccount, 1: BankAccount|null}
     */
    private function receivingAccount(CapitalContributionData $data, ?Branch $headOffice): array
    {
        if ($data->payMethod->isCash() || $data->bankAccountId === null) {
            return [$this->accounts->cashAccountFor($data->payMethod->isCash(), $headOffice), null];
        }

        $bankAccount = BankAccount::query()->with('chartAccount')->findOrFail($data->bankAccountId);
        $chart = $bankAccount->chartAccount;

        if (! $bankAccount->usage->acceptsInflow() || $bankAccount->status !== ActiveStatus::Active || $chart === null) {
            throw ValidationException::withMessages([
                'bankAccountId' => "{$bankAccount->account_name} is not an active account the company receives money into.",
            ]);
        }

        return [$chart, $bankAccount];
    }

    /**
     * The branch capital is booked against. The company profile names it; if
     * it does not, the branch flagged as head office does.
     */
    private function headOffice(): ?Branch
    {
        /*
         * `headquarters_branch_id`, not `->headquartersBranch`.
         *
         * The relation is named `headquarters` (CompanyProfile::66), so
         * `headquartersBranch` resolves to nothing at all. Outside production
         * that throws MissingAttributeException; inside it, `shouldBeStrict` is
         * off and Eloquent hands back null — so this silently always took the
         * `is_head_office` fallback and the branch configured on the company
         * profile was never consulted.
         *
         * Harmless while the profile's branch and the flag name the same
         * branch, and wrong the moment they do not: capital would be booked against the flagged branch
         * rather than the one the business named.
         *
         * Reading the column cannot go wrong that way, and it keeps the
         * nullability honest — `headquarters_branch_id` is nullable, so the
         * fallback below is the normal path rather than dead code.
         */
        $configuredId = CompanyProfile::query()->value('headquarters_branch_id');

        return ($configuredId === null ? null : Branch::query()->find($configuredId))
            ?? Branch::query()->where('is_head_office', true)->first();
    }
}
