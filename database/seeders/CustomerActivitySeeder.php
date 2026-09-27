<?php

namespace Database\Seeders;

use App\Enums\LoanStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Models\PaymentProvider;
use App\Services\LoanService;
use App\Services\PaymentService;
use App\Services\SavingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Realistic customer activity for EXISTING customers of the first company: loans in every stage (pending approval, rejected,
 * ready to pay out, active on time, active and late, fully repaid, default), Finance-entered repayments over bank and mobile
 * money, penalties, unmatched payments and savings. Everything goes through the real services, so the ledger stays balanced
 * and every report reads it like live data.
 *
 * Only KYC-verified customers of real branches (not Head Office) who have no loan yet are used, and the seeder refuses to run
 * twice (loans it creates carry the reason {@see REASON}). Money disbursed stays within the HQ PRINCIPAL A/C.
 *
 * php artisan db:seed --class=CustomerActivitySeeder
 */
class CustomerActivitySeeder extends Seeder
{
    public const REASON = 'BIASHARA (DEMO ACTIVITY)';

    /** [stage, how many] in the order customers are assigned. */
    private const PLAN = [
        ['active_on_time', 8],
        ['active_late', 6],
        ['closed', 5],
        ['default', 3],
        ['ready_to_pay', 4],
        ['pending', 6],
        ['rejected', 3],
    ];

    public function run(LoanService $loans, PaymentService $payments, SavingService $savings): void
    {
        $company = Company::orderBy('id')->firstOrFail();

        if (Loan::where('company_id', $company->id)->where('reason', self::REASON)->exists()) {
            $this->command?->warn('Customer activity was already seeded for '.$company->name.'; nothing added.');

            return;
        }

        $admin = Employee::where('company_id', $company->id)->whereHas('role', fn ($query) => $query->where('key', 'super_admin'))->orderBy('id')->firstOrFail();
        $today = CarbonImmutable::today();
        $bank = PaymentProvider::firstOrCreate(['company_id' => $company->id, 'channel' => 'BANK', 'name' => 'CRDB Bank'], ['is_active' => true]);
        $network = PaymentProvider::firstOrCreate(['company_id' => $company->id, 'channel' => 'MNO', 'name' => 'M-Pesa'], ['is_active' => true]);
        $channels = [['BANK', $bank->name], ['MNO', $network->name]];

        $weekly = LoanCategory::where('company_id', $company->id)->where('name', 'WAJASILIAMALI')->firstOrFail();
        $monthly = LoanCategory::where('company_id', $company->id)->where('name', 'NEW WATUMISHI 1')->firstOrFail();

        $customers = Customer::where('company_id', $company->id)
            ->where('kyc_status', 'completed')
            ->whereHas('branch', fn ($query) => $query->where('is_head_office', false))
            ->whereDoesntHave('loans')
            ->orderBy('id')
            ->get();

        $needed = array_sum(array_column(self::PLAN, 1));
        if ($customers->count() < $needed) {
            $this->command?->warn("Only {$customers->count()} eligible customers; {$needed} are needed.");
        }

        $counts = [];
        $index = 0;
        foreach (self::PLAN as [$stage, $howMany]) {
            foreach (range(1, $howMany) as $n) {
                $customer = $customers[$index++] ?? null;
                if ($customer === null) {
                    break 2;
                }

                DB::transaction(fn () => $this->seedCustomer($stage, $n, $customer, $weekly, $monthly, $channels, $loans, $payments, $admin, $today));
                $counts[$stage] = ($counts[$stage] ?? 0) + 1;
            }
        }

        // Late instalments get their penalties and ended loans with a balance become DEFAULT, as the daily job does.
        $loans->applyPenaltiesAndDefaults($today);

        // Money received that nobody has matched to a loan yet (Payments → Suspense Account).
        foreach ([[45000, 2], [120000, 5], [30000, 9]] as $i => [$amount, $daysAgo]) {
            [$channel, $provider] = $channels[$i % 2];
            $payments->recordUnmatched($company->id, [
                'amount' => $amount, 'channel' => $channel, 'provider' => $provider,
                'phone' => '07'.random_int(10000000, 99999999), 'paid_on' => $today->subDays($daysAgo)->toDateString(),
                'note' => 'Statement line not yet identified',
            ], $admin);
        }

        // Customer savings (Savings → Deposit & Withdrawal).
        foreach ($customers->slice(0, 10)->values() as $i => $customer) {
            $savings->deposit($customer, 10000 * ($i + 1), $admin, 'SAVING DEPOSIT');
        }

        foreach ($counts as $stage => $count) {
            $this->command?->info(str_pad($stage, 16).$count.' loans');
        }
        $this->command?->info('Unmatched payments  3');
        $this->command?->info('Savings deposits    10');
    }

    /**
     * @param  list<array{0: string, 1: string}>  $channels
     */
    private function seedCustomer(string $stage, int $n, Customer $customer, LoanCategory $weekly, LoanCategory $monthly, array $channels, LoanService $loans, PaymentService $payments, Employee $admin, CarbonImmutable $today): void
    {
        // [category, amount, sessions, days since disbursement, instalments paid on time, instalments paid late]
        [$category, $amount, $sessions, $daysAgo, $onTime, $late] = match ($stage) {
            'active_on_time' => $n % 2 === 0 ? [$monthly, 150000 + 25000 * $n, 4, 31 * min($n / 2, 3) + 2, min($n / 2, 3), 0] : [$weekly, 150000 + 25000 * $n, 3, 8, 1, 0],
            'active_late' => $n % 2 === 0 ? [$monthly, 200000 + 20000 * $n, 4, 70, 1, 1] : [$weekly, 200000 + 20000 * $n, 3, 16, 0, 1],
            'closed' => [$weekly, 100000 + 10000 * $n, 3, 30, 3, 0],
            'default' => [$monthly, 150000 + 50000 * $n, 2, 110, 1, 0],
            default => [$weekly, 180000 + 20000 * $n, 3, 0, 0, 0],
        };
        $sessions = max((int) $category->repayment_from, min((int) $category->repayment_to, $sessions));

        $loan = $loans->apply($customer, [
            'loan_category_id' => $category->id,
            'amount_applied' => $amount,
            'sessions' => $sessions,
            'formula' => 'SIMPLE',
            'fee_deduct' => (bool) $category->fee_deduct,
            'reason' => CustomerActivitySeeder::REASON,
        ], $admin);

        if ($stage === 'pending') {
            return;
        }
        if ($stage === 'rejected') {
            $loans->reject($loan);

            return;
        }

        $loans->approve($loan, $amount);
        if ($stage === 'ready_to_pay') {
            return;
        }

        $disbursed = $today->subDays($daysAgo);
        $loans->withdraw($loan->fresh(), $disbursed, $admin);
        $loan = $loan->fresh();
        $step = $category->duration === 'monthly' ? 30 : 7;

        for ($i = 1; $i <= $onTime + $late; $i++) {
            $isLate = $i > $onTime;
            $paidOn = $disbursed->addDays($step * $i + ($isLate ? 5 : -1))->min($today);
            if (! in_array($loan->fresh()->status, LoanStatus::repayable(), true)) {
                break;
            }
            $outstanding = $loans->outstanding($loan->fresh())['total'];
            // A fully repaid loan clears everything on its last instalment.
            $amountPaid = $stage === 'closed' && $i === $onTime ? $outstanding : min((float) $loan->restoration, $outstanding);
            if ($amountPaid <= 0) {
                break;
            }

            [$channel, $provider] = $channels[$i % 2];
            $payments->recordConfirmed($loan->fresh(), [
                'amount' => $amountPaid,
                'channel' => $channel,
                'provider' => $provider,
                'paid_on' => $paidOn->toDateString(),
                'note' => $isLate ? 'Paid late' : null,
            ], $admin);
        }

        if ($stage === 'closed' && $loan->fresh()->status !== LoanStatus::Closed) {
            $this->command?->warn("Loan {$loan->loan_number} was expected to close but is {$loan->fresh()->status->value}.");
        }
    }
}
