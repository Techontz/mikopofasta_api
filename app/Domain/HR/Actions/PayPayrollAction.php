<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Hr\Enums\PayrollRunStatus;
use App\Domain\Hr\Exceptions\PayrollStateException;
use App\Domain\Hr\Services\PayrollPostingBuilder;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Enums\AuditAction;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `POST /payroll/{run}/pay` — executing the payment (§11: "Payment executed →
 * Dr Staff Payable / Cr HQ Cash").
 *
 * Also Finance's, and deliberately a separate act from finalization. Between
 * the two the company has recognised what it owes but has not yet paid it, and
 * that gap is real: a finalized run can sit for days waiting on a bank
 * transfer window. Collapsing the two would erase the period in which Staff
 * Payable legitimately carries a balance.
 *
 * A line whose net pay is zero or negative is skipped rather than posted: a
 * journal line must carry a positive amount, and an employee whose deductions
 * consumed their whole salary is owed nothing this month — there is no payment
 * to record.
 */
final class PayPayrollAction
{
    public function __construct(
        private readonly PayrollPostingBuilder $postings,
        private readonly LedgerService $ledger,
        private readonly AccountResolver $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(PayrollRun $run, User $actor): PayrollRun
    {
        if ($run->status !== PayrollRunStatus::Finalized) {
            throw PayrollStateException::notFinalized();
        }

        $run->load(['lines.staffProfile.user']);

        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $bank = $this->accounts->defaultBankAccount();
            $paid = Money::zero();
            $skipped = 0;

            foreach ($run->lines as $line) {
                if (! $line->netSalary()->isPositive()) {
                    $skipped++;

                    continue;
                }

                $name = $line->staffProfile->displayName();

                $this->ledger->post(
                    description: sprintf('Salary payment — %s (%s)', $name, $run->period),
                    sourceType: JournalSourceType::Payroll,
                    sourceId: (int) $run->getKey(),
                    lines: $this->postings->buildPayment($line, $bank),
                    postedBy: $actor,
                );

                $paid = $paid->add($line->netSalary());
            }

            $run->update(['status' => PayrollRunStatus::Paid]);

            $this->audit->log(
                AuditAction::PayrollPaid,
                $run,
                after: [
                    'period' => $run->period,
                    'total_paid' => $paid->toDecimalString(),
                    'lines_paid' => $run->lines->count() - $skipped,
                    'lines_skipped' => $skipped,
                    'bank_account' => $bank->code,
                ],
                actor: $actor,
            );

            Log::channel('operations')->info('Payroll paid', [
                'period' => $run->period,
                'run_id' => $run->getKey(),
                'total_paid' => $paid->toDecimalString(),
                'lines_paid' => $run->lines->count() - $skipped,
                'lines_skipped' => $skipped,
                'bank_account' => $bank->code,
            ]);

            return $run->fresh(['lines.staffProfile']);
        });
    }
}
