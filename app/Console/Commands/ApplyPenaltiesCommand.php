<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Repayments\Actions\RunOverdueProcessAction;
use App\Domain\Repayments\Enums\TriggeredBy;
use App\Models\PenaltyRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * §7's overdue/penalty job — `penalty:apply`.
 *
 * The specification calls for a scheduled job, and until now only the manual
 * endpoint existed: `POST /loans/overdue/process`. Both call the SAME action,
 * so the cron and the button cannot diverge. Nothing about the calculation
 * lives here; this class decides only *when* and *as whom*.
 *
 * §7's penalty accrual posts nothing to the ledger (OSC-1) — penalty income is
 * recognised on collection. That decision is unchanged and is restated in the
 * command's own output so an operator reading the cron log is not left
 * wondering why the trial balance did not move.
 *
 * The action DOES post one thing before it starts: held Customer Advances being
 * spent on installments that have just fallen due. So the trial balance can move
 * on a night this command runs, and `advance_consumption` is what moved it. The
 * two are one job on purpose — an installment the borrower has already funded
 * must be settled before anything judges it late.
 */
final class ApplyPenaltiesCommand extends Command
{
    protected $signature = 'penalty:apply';

    protected $description = 'Apply penalties to overdue installments and move affected loans into arrears (spec §7)';

    public function handle(RunOverdueProcessAction $action): int
    {
        /*
         * No actor is passed, and the action resolves the System account.
         *
         * The scheduler is not a person, and attributing the run to one would
         * put a name against a decision they did not make. The System account
         * is the answer to that: automated work has an identity of its own, so
         * the run is attributable without being misattributed. If the platform
         * has not been initialised the action refuses outright rather than
         * running as somebody.
         */
        Log::channel('operations')->info('Penalty run starting', ['triggered_by' => TriggeredBy::Cron->value]);

        try {
            $run = $action->handle(TriggeredBy::Cron);
        } catch (Throwable $e) {
            /*
             * Logged and re-thrown rather than swallowed. A silent failure
             * would mean penalties quietly stopped accruing, which nobody
             * would notice until a borrower disputed an arrears figure.
             */
            Log::channel('operations')->error('Penalty run failed', [
                'triggered_by' => TriggeredBy::Cron->value,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->report($run);

        return self::SUCCESS;
    }

    private function report(PenaltyRun $run): void
    {
        $context = [
            'run_id' => $run->getKey(),
            'run_date' => $run->run_date->toDateString(),
            'loans_processed' => $run->loans_processed,
            'installments_penalised' => $run->installments_penalised,
            'total_penalty_applied' => $run->total_penalty_applied,
            'ledger_posting' => 'none (OSC-1: penalty income is recognised on collection)',
        ];

        Log::channel('operations')->info('Penalty run complete', $context);

        $this->info(sprintf(
            'Penalty run %s: %d loans, %d installments, %s applied. No ledger entry — penalty income is recognised on collection (OSC-1).',
            $run->run_date->toDateString(),
            $run->loans_processed,
            $run->installments_penalised,
            $run->total_penalty_applied,
        ));
    }
}
