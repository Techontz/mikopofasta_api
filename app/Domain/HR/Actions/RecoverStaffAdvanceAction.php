<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Hr\Enums\StaffAdvanceStatus;
use App\Domain\Hr\Services\SalaryAdvanceCalculator;
use App\Enums\AuditAction;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;

/**
 * Credits an advance with money recovered from a payslip, and closes it when
 * the balance clears.
 *
 * Called by FinalizePayrollAction once the deduction entry has posted — never
 * at generation, so an advance's balance cannot run ahead of the ledger that
 * recovered it.
 *
 * ## What this fixes
 *
 * Nothing previously set `StaffAdvanceStatus::Recovered`. A disbursed advance
 * stayed outstanding for ever, `hasOutstandingAdvance()` kept returning true,
 * and payroll deducted against it every single month with no end — real money,
 * taken indefinitely, from someone who had already repaid.
 *
 * ## No ledger posting here
 *
 * The recovery is already in the books: PayrollPostingBuilder's deduction entry
 * credits `7020 Staff Advance Receivable` as part of Dr Staff Payable / Cr each
 * deduction's account. Posting again here would credit the receivable twice and
 * the advance would appear to repay itself at double rate. This action moves no
 * money; it records against the advance what the payroll entry already moved.
 */
final class RecoverStaffAdvanceAction
{
    public function __construct(
        private readonly SalaryAdvanceCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    public function recover(StaffAdvance $advance, Money $amount, User $actor): StaffAdvance
    {
        if (! $amount->isPositive()) {
            return $advance;
        }

        /*
         * Capped again here, not only in the calculator. This action is
         * reachable from any caller with an amount, and an advance must never
         * record more recovered than it was ever owed — that would show a
         * negative balance on the Active screen and a credit nobody owes.
         */
        $applied = $amount->greaterThan($this->calculator->outstanding($advance))
            ? $this->calculator->outstanding($advance)
            : $amount;

        if (! $applied->isPositive()) {
            return $advance;
        }

        $before = $advance->amount_recovered;
        $settles = $this->calculator->settles($advance, $applied);

        $advance->update([
            'amount_recovered' => $advance->recoveredMoney()->add($applied)->toDecimalString(),
            'status' => $settles ? StaffAdvanceStatus::Recovered : $advance->status,
            'recovered_at' => $settles ? Date::now() : null,
        ]);

        $this->audit->log(
            $settles ? AuditAction::StaffAdvanceRecovered : AuditAction::StaffAdvanceRepaid,
            $advance,
            before: ['amount_recovered' => $before, 'status' => StaffAdvanceStatus::Disbursed->value],
            after: [
                'amount_recovered' => $advance->amount_recovered,
                'status' => $advance->status->value,
                'recovered_this_period' => $applied->toDecimalString(),
            ],
            actor: $actor,
        );

        return $advance;
    }
}
