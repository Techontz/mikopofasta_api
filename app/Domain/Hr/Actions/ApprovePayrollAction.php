<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Hr\Enums\PayrollRunStatus;
use App\Domain\Hr\Exceptions\PayrollStateException;
use App\Enums\AuditAction;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `POST /payroll/{run}/approve` — HR signs the figures off.
 *
 * ## Why this step exists
 *
 * §16.1 of the HR document: *"Salary haiwezi kubadilishwa baada ya approval"* —
 * salary cannot be changed after approval. That sentence needs a moment at
 * which approval happened, and the run had none. HR generated a draft that
 * could be regenerated at will and Finance posted whatever the draft held, so
 * there was no point at which the figures became **the agreed figures**.
 *
 * §16.7 and §16.8 say who does which: *"Malipo yote HR ata-approval"* and
 * *"disbursement zote zitafanyika finance"*. HR approves; Finance disburses.
 * So the grant here is HR's — `payroll.generate`, the same one that produced
 * the draft — and finalisation stays behind Finance's `payroll.finalize`.
 *
 * ## What approval does, and does not, do
 *
 * It posts nothing. Not a single journal line is written until Finance
 * finalizes, and that separation is §14's whole point: the person who decides
 * what everyone is owed is not the person who releases the money.
 *
 * What it does is close the figures. From here `GeneratePayrollAction` refuses
 * to regenerate the period and the run can only move forward — which is what
 * makes §16.1 enforceable rather than a statement of intent.
 */
final class ApprovePayrollAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PayrollRun $run, User $actor): PayrollRun
    {
        if ($run->status !== PayrollRunStatus::Draft) {
            throw PayrollStateException::alreadyApproved();
        }

        $run->loadMissing('lines');

        // A run with no lines would approve nothing and then finalize to
        // nothing, leaving an "approved" period that pays no one.
        if ($run->lines->isEmpty()) {
            throw PayrollStateException::noLines();
        }

        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $run->update([
                'status' => PayrollRunStatus::Approved,
                'approved_by' => $actor->getKey(),
                'approved_at' => Date::now(),
            ]);

            $this->audit->log(
                AuditAction::PayrollApproved,
                $run,
                before: ['status' => PayrollRunStatus::Draft->value],
                after: [
                    'status' => PayrollRunStatus::Approved->value,
                    'period' => $run->period,
                    'lines' => $run->lines->count(),
                    'net_payable' => $run->netTotal()->toDecimalString(),
                    // Said plainly, so a reader of the trail does not have to
                    // infer it from the absence of an entry number.
                    'ledger_posting' => 'none (approval agrees the figures — Finance posts them)',
                ],
                actor: $actor,
            );

            Log::channel('operations')->info('Payroll approved', [
                'period' => $run->period,
                'run_id' => $run->getKey(),
                'approved_by' => $actor->getKey(),
                'net_payable' => $run->netTotal()->toDecimalString(),
            ]);

            return $run->fresh(['lines.staffProfile', 'lines.allowances', 'lines.deductions']);
        });
    }
}
