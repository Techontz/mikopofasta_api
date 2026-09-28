<?php

namespace App\Console\Commands;

use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Services\Hrm\CommissionEngine;
use App\Services\PeriodClose;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Close the previous month for every company and calculate that month's commission (specification §16 and §21: the period
 * closes automatically on the 1st day of the new month — on 1 August the system closes July — and staff then see their
 * July commission as calculated and awaiting payment, whatever date it is eventually paid on).
 *
 * The scheduler runs this on the 1st; it is safe to run again by hand, because a period that is already closed is skipped
 * and commission that is already calculated is left alone. Nothing here pays anybody: payment stays a Finance decision.
 */
class CloseMonth extends Command
{
    protected $signature = 'mkopa:close-month {--month= : The month to close (YYYY-MM), default the month before today} {--company= : Only this company id}';

    protected $description = 'Close the previous accounting period for every company and calculate its commission';

    public function handle(PeriodClose $periods, CommissionEngine $commissions): int
    {
        $month = $this->option('month') !== null
            ? CarbonImmutable::createFromFormat('Y-m', (string) $this->option('month'))->startOfMonth()
            : CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();

        $companies = Company::query()
            ->when($this->option('company') !== null, fn ($query) => $query->whereKey((int) $this->option('company')))
            ->orderBy('id')->get();

        $label = $month->format('Y-m');
        $blocked = 0;
        foreach ($companies as $company) {
            $closed = AccountingPeriod::query()
                ->where('company_id', $company->id)
                ->whereDate('period_start', $month->toDateString())
                ->first()?->isClosed() ?? false;

            // An earlier period still open, or commission already locked into a payroll or a dividend declaration: the
            // month needs a person, so say why and carry on with the other companies.
            $blocked += $this->attempt("{$company->name}: {$label}", function () use ($periods, $company, $month, $closed, $label): void {
                if ($closed) {
                    $this->line("{$company->name}: {$label} already closed");

                    return;
                }

                $periods->close($periods->calculate($company, $month));
                $this->info("{$company->name}: {$label} closed");
            });

            $blocked += $this->attempt("{$company->name}: {$label} commission", function () use ($commissions, $company, $month, $label): void {
                $allocations = $commissions->calculate((int) $company->id, $month);
                $this->line("{$company->name}: {$label} commission calculated for {$allocations->count()} employee(s)");
            });
        }

        return $blocked > 0 && $companies->isEmpty() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Run one step, reporting a business refusal (period or commission locked) as a warning rather than a crash: the other
     * companies must still close. Returns 1 when the step was refused.
     */
    private function attempt(string $label, callable $step): int
    {
        try {
            $step();

            return 0;
        } catch (ValidationException $exception) {
            $this->warn("{$label} skipped — ".implode(' ', $exception->validator->errors()->all()));

            return 1;
        }
    }

    /**
     * The period the scheduled run would close next, for the Finance screens that show when the month closes.
     */
    public static function nextClosing(int $companyId): ?AccountingPeriod
    {
        return AccountingPeriod::query()
            ->where('company_id', $companyId)
            ->where('status', AccountingPeriod::STATUS_OPEN)
            ->whereDate('period_end', '<', CarbonImmutable::today()->toDateString())
            ->orderBy('period_start')
            ->first();
    }
}
