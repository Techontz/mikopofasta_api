<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Treasury\DTOs\CapitalContributionData;
use App\Enums\AuditAction;
use App\Models\Branch;
use App\Models\CapitalContribution;
use App\Models\CompanyProfile;
use App\Models\Shareholder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Records capital paid in by a shareholder (POST /capital-contributions).
 *
 * Posts double-entry through LedgerService, the only code path allowed to
 * write journal lines (§5):
 *
 *     Dr  head-office cash or bank   (where the money landed)
 *     Cr  1000 Capital Account       (what the company now owes its owners)
 *
 * Cash lands in the head-office till, a cheque or transfer in the bank —
 * decided by AccountResolver::cashAccountFor(), the same helper a cash
 * repayment uses, so capital lands where money always lands.
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

            $cashAccount = $this->accounts->cashAccountFor($data->payMethod->isCash(), $headOffice);

            $entry = $this->ledger->post(
                sprintf('Capital from %s', $shareholder->full_name),
                JournalSourceType::CapitalInjection,
                null,
                [
                    JournalLine::debit($cashAccount->id, $amount, branchId: $headOffice?->id),
                    JournalLine::credit($this->accounts->systemId(SystemAccountCode::Capital), $amount),
                ],
                $actor,
            );

            $contribution = CapitalContribution::query()->create([
                'shareholder_id' => $shareholder->id,
                'amount' => $data->amount,
                'pay_method' => $data->payMethod,
                'receipt_no' => $data->receiptNo,
                'cheque_no' => $data->chequeNo,
                'journal_entry_id' => $entry->id,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::CapitalRecorded,
                $contribution,
                after: [
                    'shareholder_id' => $shareholder->id,
                    'amount' => $contribution->amount,
                    'pay_method' => $data->payMethod->value,
                    'journal_entry_id' => $entry->id,
                ],
                actor: $actor,
            );

            return $contribution->load('shareholder');
        });
    }

    /**
     * The branch capital is booked against. The company profile names it; if
     * it does not, the branch flagged as head office does.
     */
    private function headOffice(): ?Branch
    {
        $profile = CompanyProfile::query()->first();

        return $profile?->headquartersBranch
            ?? Branch::query()->where('is_head_office', true)->first();
    }
}
