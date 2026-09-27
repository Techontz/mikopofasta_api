<?php

namespace Database\Seeders;

use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\BranchPeriodResult;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DividendDeclaration;
use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\ShareHolder;
use App\Models\ShareTransaction;
use App\Services\Hrm\CommissionEngine;
use App\Services\Shares\ShareRegister;
use Carbon\CarbonImmutable;
use Database\Seeders\DevSeed\Api;
use Database\Seeders\DevSeed\CapitalPhase;
use Database\Seeders\DevSeed\Context;
use Database\Seeders\DevSeed\CustomerPhase;
use Database\Seeders\DevSeed\FinancePhase;
use Database\Seeders\DevSeed\HrmPhase;
use Database\Seeders\DevSeed\LoanPhase;
use Database\Seeders\DevSeed\OrganisationPhase;
use Database\Seeders\DevSeed\Timeline;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Realistic, internally consistent development data for browser testing (April – 14 September 2026): branches and
 * staff, customers of all five customer types, loans in every workflow state, repayments, expenses, capital, shares,
 * assets, month-end closes of April – June, June commission and payroll, a partially paid June dividend, HRM records and
 * attendance.
 *
 * Every action goes through the application's own API (form requests, permissions, services, ledger) as the employee who
 * would perform it, in date order under the test clock, so journals are dated inside the right accounting periods. Rows are
 * identified by natural keys (see DevSeed\Catalog) and each step is skipped when it already exists: running the seeder
 * again changes nothing. The run is one database transaction. Never runs in production.
 *
 *   php artisan db:seed --class=DevelopmentTestDataSeeder
 */
class DevelopmentTestDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DevelopmentTestDataSeeder must never run in production.');
        }

        $company = Company::where('phone', config('demo.admin_phone'))->first()
            ?? throw new RuntimeException('Run MasterDataSeeder first: the demo company is missing.');

        $before = $this->counts($company);
        $log = fn (string $line) => $this->command?->getOutput()->isVerbose() ? $this->command->line("  {$line}") : null;
        $context = new Context(new Api(app(Kernel::class)), new Timeline, $company, $log);
        $context->historyComplete = DividendDeclaration::where('company_id', $company->id)->whereDate('period', CapitalPhase::DIVIDEND_PERIOD)->exists();

        (new OrganisationPhase($context))->register();
        (new CapitalPhase($context))->register();
        (new CustomerPhase($context))->register();
        (new LoanPhase($context))->register();
        (new FinancePhase($context))->register();
        (new HrmPhase($context))->register();

        $started = microtime(true);

        try {
            $stats = DB::transaction(fn (): array => $context->timeline->run($log));
        } finally {
            Timeline::travelBack();
        }

        $this->summary($company, $before, $this->counts($company), $stats, $context->api->requests(), microtime(true) - $started);
    }

    /**
     * @return array<string, int>
     */
    private function counts(Company $company): array
    {
        $id = $company->id;

        return [
            'companies' => Company::count(),
            'branches' => Branch::where('company_id', $id)->count(),
            'staff' => Employee::where('company_id', $id)->count(),
            'customers' => Customer::where('company_id', $id)->count(),
            'shareholders' => ShareHolder::where('company_id', $id)->count(),
            'share_transactions' => ShareTransaction::where('company_id', $id)->count(),
            'issued_shares' => app(ShareRegister::class)->issuedShares($id),
            'assets' => Asset::where('company_id', $id)->count(),
            'loans' => Loan::where('company_id', $id)->count(),
            'repayments' => LoanTransaction::where('company_id', $id)->where('type', 'deposit')->count(),
            'expenses' => ExpenseRequest::where('company_id', $id)->count(),
            'journal_entries' => JournalEntry::where('company_id', $id)->count(),
            'attendance' => Attendance::where('company_id', $id)->count(),
        ];
    }

    /**
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     * @param  array{executed: int, skipped: int}  $stats
     */
    private function summary(Company $company, array $before, array $after, array $stats, int $requests, float $seconds): void
    {
        $period = AccountingPeriod::where('company_id', $company->id)->whereDate('period_start', FinancePhase::PAYROLL_PERIOD.'-01')->first();
        $report = app(CommissionEngine::class)->report($company->id, CarbonImmutable::parse(FinancePhase::PAYROLL_PERIOD.'-01'));
        $distributable = $period === null ? 0.0 : (float) BranchPeriodResult::where('accounting_period_id', $period->id)->sum('distributable_profit');
        $pool = round((float) collect($report['branches'])->sum('pool_amount'), 2);

        $rows = [
            ['COMPANIES', 'companies'], ['BRANCHES', 'branches'], ['STAFF', 'staff'], ['CUSTOMERS', 'customers'], ['SHAREHOLDERS', 'shareholders'],
            ['SHARES (issued)', 'issued_shares'], ['SHARE TRANSACTIONS', 'share_transactions'], ['ASSETS', 'assets'], ['LOANS', 'loans'],
            ['REPAYMENTS', 'repayments'], ['EXPENSES', 'expenses'], ['ACCOUNTING TRANSACTIONS', 'journal_entries'], ['ATTENDANCE RECORDS', 'attendance'],
        ];

        $this->command?->newLine();
        $this->command?->info('DEVELOPMENT TEST DATA — '.$company->name);
        $this->command?->table(['Item', 'Total', 'Added this run'], array_map(fn (array $row): array => [$row[0], number_format($after[$row[1]]), number_format($after[$row[1]] - $before[$row[1]])], $rows));
        $this->command?->table(['Commission test', 'Value'], [
            ['COMMISSION TEST PERIOD', 'June 2026'],
            ['PERIOD STATUS', $period === null ? 'NOT CALCULATED' : strtoupper($period->status).($report['period_closed'] ? ' (commission page: closed)' : ' (commission page: PERIOD NOT CLOSED)')],
            ['DISTRIBUTABLE PROFIT TZS', number_format($distributable, 2)],
            ['COMMISSION POOL TZS', number_format($pool, 2)],
            ['COMMISSION CALCULATED TZS', number_format((float) $report['total_commission'], 2).($report['calculated'] ? '' : ' (preview)')],
        ]);
        $this->command?->line(sprintf('Timeline: %d steps executed, %d already present (skipped); %d API requests; %.1fs.', $stats['executed'], $stats['skipped'], $requests, $seconds));
    }
}
