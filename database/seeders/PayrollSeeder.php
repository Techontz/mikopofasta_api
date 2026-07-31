<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Hr\Actions\FinalizePayrollAction;
use App\Domain\Hr\Actions\GenerateCommissionPoolsAction;
use App\Domain\Hr\Actions\GeneratePayrollAction;
use App\Domain\Hr\Actions\PayPayrollAction;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * Runs one full monthly cycle: commission pools, then payroll generated,
 * finalized and paid.
 *
 * Everything goes through the SAME actions the API uses — the commission
 * engine, the payroll calculator, the posting builder, LedgerService. Nothing
 * here writes a payroll line or a journal row directly. A seeder with its own
 * payroll arithmetic would produce a book that balances in the seed and
 * nowhere else, and would hide exactly the bugs these numbers exist to expose.
 *
 * The period is the current month, because that is where the seeded loan book
 * earned its interest — a run for last month would compute every branch's
 * profit from an empty ledger and produce nothing but zero pools, which would
 * demonstrate neither the commission engine nor §11's loss rule.
 *
 * Note the ordering, which is §11's and not incidental: commission is computed
 * from the month's profit *before* payroll posts its salary expense into that
 * same month. Re-running commission generation afterwards would legitimately
 * produce smaller pools, because the salaries are now part of the period's
 * costs. §11 resolves that circularity by sequencing close → commission →
 * payroll, and this seeder follows it.
 */
final class PayrollSeeder extends Seeder
{
    public function run(): void
    {
        $hr = User::query()->where('phone', '0754000007')->first();
        $finance = User::query()->where('phone', '0754000003')->first();

        if ($hr === null || $finance === null) {
            return;
        }

        $period = Date::now()->format('Y-m');

        if (PayrollRun::query()->where('period', $period)->exists()) {
            return;
        }

        // Finance closes the month and computes what each branch earned.
        app(GenerateCommissionPoolsAction::class)->handle($period, $finance);

        // HR produces the draft; nothing is posted yet.
        $run = app(GeneratePayrollAction::class)->handle($period, $hr);

        // Finance finalizes — recognition and deduction entries — then pays.
        app(FinalizePayrollAction::class)->handle($run, $finance);
        app(PayPayrollAction::class)->handle($run->fresh(), $finance);
    }
}
