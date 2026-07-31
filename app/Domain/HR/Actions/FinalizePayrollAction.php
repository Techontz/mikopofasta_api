<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\Enums\PayrollRunStatus;
use App\Domain\Hr\Exceptions\PayrollStateException;
use App\Domain\Hr\Services\PayrollPostingBuilder;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\LedgerService;
use App\Enums\AuditAction;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Models\ZoneCommissionDistribution;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `POST /payroll/{run}/finalize` — §15.5, Finance's step and the one that
 * posts.
 *
 * §14 is unambiguous: "HR can generate payroll but not finalize/pay it
 * (Finance does)." Everything up to this point has been arithmetic in a draft;
 * this is where the company recognises a cost and a debt to its employees.
 *
 * Two entries per employee, both through `LedgerService` like every other
 * financial event in the system:
 *
 *   Dr Salary Expense / Dr Commission Expense · Cr Staff Payable
 *   Dr Staff Payable · Cr Staff Fund / Staff Loan Rec. / Staff Advance Rec.
 *
 * Everything happens in ONE transaction. A run that was marked finalized but
 * whose entries did not commit would be a period the books have never seen and
 * that nobody can finalize again.
 */
final class FinalizePayrollAction
{
    public function __construct(
        private readonly PayrollPostingBuilder $postings,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(PayrollRun $run, User $actor): PayrollRun
    {
        if (! $run->isDraft()) {
            throw PayrollStateException::notDraft();
        }

        $run->load(['lines.staffProfile.user', 'lines.deductions']);

        if ($run->lines->isEmpty()) {
            throw PayrollStateException::noLines();
        }

        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $recognised = Money::zero();

            foreach ($run->lines as $line) {
                $recognised = $recognised->add($this->postLine($run, $line, $actor));
            }

            $run->update([
                'status' => PayrollRunStatus::Finalized,
                'finalized_at' => Date::now(),
            ]);

            $this->linkZoneOverrides($run);

            $this->audit->log(
                AuditAction::PayrollFinalized,
                $run,
                after: [
                    'period' => $run->period,
                    'lines' => $run->lines->count(),
                    'gross_recognised' => $recognised->toDecimalString(),
                    'net_payable' => $run->netTotal()->toDecimalString(),
                ],
                actor: $actor,
            );

            Log::channel('operations')->info('Payroll finalized and posted', [
                'period' => $run->period,
                'run_id' => $run->getKey(),
                'lines' => $run->lines->count(),
                'gross_recognised' => $recognised->toDecimalString(),
                'net_payable' => $run->netTotal()->toDecimalString(),
            ]);

            return $run->fresh(['lines.staffProfile', 'lines.allowances', 'lines.deductions']);
        });
    }

    /**
     * Posts one employee's recognition and deduction entries.
     *
     * Returns the gross recognised, so the audit row can record what the run
     * actually cost without re-summing the lines.
     */
    private function postLine(PayrollRun $run, PayrollLine $line, User $actor): Money
    {
        $name = $line->staffProfile->displayName();

        $recognition = $this->ledger->post(
            description: sprintf('Payroll recognition — %s (%s)', $name, $run->period),
            sourceType: JournalSourceType::Payroll,
            sourceId: (int) $run->getKey(),
            lines: $this->postings->buildRecognition($line),
            postedBy: $actor,
        );

        $deductionLines = $this->postings->buildDeductions($line, $line->deductions);

        if ($deductionLines !== []) {
            $this->ledger->post(
                description: sprintf('Payroll deductions — %s (%s)', $name, $run->period),
                sourceType: JournalSourceType::Payroll,
                sourceId: (int) $run->getKey(),
                lines: $deductionLines,
                postedBy: $actor,
            );
        }

        // The line points at the recognition entry — the one that created the
        // obligation. The deduction entry is reachable from the run's source
        // id, and a line can only carry one reference.
        $line->update(['journal_entry_id' => $recognition->getKey()]);

        return $line->grossPay();
    }

    /**
     * Points each zone override at the entry that expensed it.
     *
     * §11's override is folded into the zone manager's own commission figure
     * and therefore into their recognition entry. Recording that entry id here
     * — rather than posting a second one — is what keeps the money recognised
     * exactly once while still leaving the override traceable.
     */
    private function linkZoneOverrides(PayrollRun $run): void
    {
        $overrides = ZoneCommissionDistribution::query()
            ->where('period', $run->period)
            ->whereNull('journal_entry_id')
            ->get();

        foreach ($overrides as $override) {
            $line = $run->lines->first(
                fn (PayrollLine $l): bool => $l->staffProfile->user->zone_id === $override->zone_id
                    && $l->staffProfile->user->roleName() === RoleName::ZoneManager,
            );

            if ($line?->journal_entry_id === null) {
                continue;
            }

            $override->update(['journal_entry_id' => $line->journal_entry_id]);
        }
    }
}
