<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Empty every transaction table so the system starts from zero, keeping setup data: companies, branches, zones, employees
 * and their HR records, customers and their documents, shareholders, banks, categories, formulas and all reference data.
 *
 * Irreversible — take a database backup first (storage/app/backups). Local/demo use only.
 */
class ClearTransactions extends Command
{
    protected $signature = 'mkopa:clear-transactions {--force : Skip the confirmation}';

    protected $description = 'Delete every transaction (ledger, loans, payments, capital, floats, expenses, payroll) and keep users and setup data';

    /**
     * Transaction tables, children before parents.
     *
     * @var list<string>
     */
    public const TABLES = [
        // Ledger and period results
        'journal_lines', 'journal_entries', 'accounting_periods', 'branch_period_results', 'commission_allocations',
        // Money movements
        'agent_transactions', 'bank_transfers', 'float_transfers', 'hq_transactions', 'teller_deposits',
        // Capital, shares, dividends and assets contributed as capital
        'capitals', 'dividend_payments', 'dividend_payment_batches', 'dividend_allocations', 'dividend_declarations',
        'share_transactions', 'share_positions', 'share_valuations', 'share_structures',
        'asset_documents', 'asset_events', 'assets',
        // Loans and repayments
        'loan_recoveries', 'write_offs', 'loan_disbursements', 'loan_mandates', 'loan_schedules', 'loan_transactions',
        'payment_allocations', 'payments', 'penalty_payments', 'penalties', 'collaterals', 'guarantors', 'loans',
        // Savings, salary advances, staff credit and payroll
        'savings', 'salary_advance_payments', 'salary_advances', 'staff_loan_payments', 'staff_loans',
        'staff_salary_advances', 'staff_fund_withdrawals', 'payroll_items', 'salary_payments', 'payroll_runs',
        // Expenses
        'expense_requests',
        // Housekeeping tied to the records above
        'idempotent_requests', 'audit_logs',
    ];

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();

        if (! $this->option('force') && ! $this->confirm("Delete every transaction in {$database}? This cannot be undone.")) {
            $this->warn('Nothing was deleted.');

            return self::FAILURE;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $cleared = 0;
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)->count();
            DB::table($table)->truncate();
            $cleared += $rows;
            $this->line(str_pad($table, 34).str_pad((string) $rows, 8).'→ 0');
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->info("{$cleared} rows deleted from {$database}. Users, customers, branches, shareholders and settings are untouched.");

        return self::SUCCESS;
    }
}
