<?php

namespace Database\Seeders\DevSeed;

use App\Enums\Account;
use App\Models\AccountingPeriod;
use App\Models\CommissionAllocation;
use App\Models\ExpenseRequest;
use App\Models\ExpenseType;
use App\Models\PayrollRun;
use App\Services\Hrm\PayrollEngine;
use App\Services\Ledger;
use Carbon\CarbonImmutable;

/**
 * Expense requests and approvals, the month-end close of April, May and June 2026, June commission and the June payroll
 * (generate → approve → pay), all through the Accounting, Expenses and HRM endpoints.
 */
final class FinancePhase
{
    public const PAYROLL_PERIOD = '2026-06';

    public function __construct(private readonly Context $ctx) {}

    public function register(): void
    {
        $this->expenses();
        $this->closes();
        $this->commissionAndPayroll();
    }

    /**
     * @return list<array{0: string, 1: string|null, 2: string, 3: string, 4: int, 5: string, 6: bool}>
     */
    public static function expenseList(): array
    {
        $rows = [];
        foreach (Catalog::NEW_BRANCHES as $branch) {
            $name = Catalog::BRANCHES[$branch][0];
            $rows[] = ['2026-04-25', $branch, 'branch', 'umeme', 45000, "Bili ya umeme Aprili 2026 - {$name}", true];
            $rows[] = ['2026-05-28', $branch, 'branch', 'Kodi ya Ofisi', 350000, "Kodi ya ofisi Mei 2026 - {$name}", true];
            $rows[] = ['2026-05-28', $branch, 'branch', 'Usafiri', 60000, "Usafiri wa kufuatilia wateja Mei 2026 - {$name}", true];
            $rows[] = ['2026-06-26', $branch, 'branch', 'Mawasiliano', 40000, "Vocha na bando za simu za ofisi Juni 2026 - {$name}", true];
            $rows[] = ['2026-06-26', $branch, 'branch', 'Vifaa vya Ofisi', 85000, "Karatasi, wino na vitabu vya stakabadhi - {$name}", true];
            $rows[] = $branch === 'KB'
                ? ['2026-07-28', $branch, 'branch', 'Matengenezo', 650000, 'Ukarabati mkubwa wa pikipiki mbili za tawi - Kibondo', true]
                : ['2026-07-28', $branch, 'branch', 'Matengenezo', 120000, "Matengenezo ya pikipiki ya ofisi - {$name}", true];
            $rows[] = ['2026-08-27', $branch, 'branch', 'Kodi ya Ofisi', 350000, "Kodi ya ofisi Agosti 2026 - {$name}", true];
            $rows[] = ['2026-09-10', $branch, 'branch', 'Usafi', 30000, "Huduma ya usafi Septemba 2026 - {$name}", ! in_array($branch, ['KB', 'KS'], true)];
        }
        foreach (['KK', 'MS', 'LN'] as $branch) {
            $name = Catalog::BRANCHES[$branch][0];
            $rows[] = ['2026-05-22', $branch, 'branch', 'MAJI', 25000, "Bili ya maji Mei 2026 - {$name}", true];
            $rows[] = ['2026-06-24', $branch, 'branch', 'umeme', 55000, "Bili ya umeme Juni 2026 - {$name}", true];
        }
        $rows[] = ['2026-05-20', null, 'hq', 'Ukaguzi wa Hesabu', 1200000, 'Ada ya ukaguzi wa hesabu robo ya kwanza 2026', true];
        $rows[] = ['2026-06-05', null, 'hq', 'MAFUTA', 180000, 'Mafuta ya gari la ukaguzi wa matawi ya Kigoma', true];
        $rows[] = ['2026-07-15', null, 'hq', 'Mafunzo ya Wafanyakazi', 650000, 'Mafunzo ya huduma kwa wateja - maafisa mikopo Kigoma', true];
        $rows[] = ['2026-09-12', null, 'hq', 'Leseni na Vibali', 450000, 'Ada ya leseni ya taasisi ya fedha daraja la pili', false];
        $rows[] = ['2026-08-29', null, 'bank', 'Ada za Benki', 25000, 'Ada za huduma za benki NMB Agosti 2026', true];

        return $rows;
    }

    private function expenses(): void
    {
        foreach (self::expenseList() as $number => [$date, $branch, $scope, $type, $amount, $text, $accept]) {
            $marker = Context::marker(sprintf('EXP-%03d', $number + 1));
            $description = "{$text} {$marker}";
            $column = $scope === 'bank' ? 'comment' : 'description';
            $request = fn (): ?ExpenseRequest => ExpenseRequest::where('company_id', $this->ctx->company->id)->where($column, $description)->first();

            $this->ctx->timeline->at(CarbonImmutable::parse($date)->setTime(10, 0)->addMinutes($number), "expense request {$marker}", function () use ($branch, $scope, $type, $amount, $description): void {
                $typeId = ExpenseType::where('company_id', $this->ctx->company->id)->where('scope', $scope)->where('name', $type)->value('id');
                [$actor, $payload] = match ($scope) {
                    'branch' => [$this->ctx->branchActor($branch, 'branch_manager'), ['scope' => 'branch', 'blanch_id' => $this->ctx->branch($branch)->id, 'ex_id' => $typeId, 'req_amount' => $amount, 'req_description' => $description]],
                    'hq' => [$this->ctx->staff('HQ_FIN2'), ['scope' => 'hq', 'ex_id' => $typeId, 'req_amount' => $amount, 'req_description' => $description]],
                    'bank' => [$this->ctx->staff('HQ_FIN2'), ['scope' => 'bank', 'ac_id' => $this->ctx->bank('NMB')->id, 'exp_id' => $typeId, 'amount' => $amount, 'comment' => $description]],
                };
                $this->ctx->api->call($actor, 'POST', 'expenses/requests', $payload);
            }, fn (): bool => $request() !== null);

            if (! $accept) {
                continue;
            }

            $this->ctx->timeline->at(CarbonImmutable::parse($date)->setTime(15, 0)->addMinutes($number), "expense approval {$marker}", function () use ($request, $scope, $amount, $branch): void {
                if ($scope === 'branch') {
                    $this->ensureInterest($this->ctx->branch($branch)->id, $amount);
                }
                $approver = $scope === 'branch' && $amount <= 500000 ? $this->ctx->staff('HQ_FIN2') : $this->ctx->staff('HQ_ADM');
                $this->ctx->api->call($approver, 'POST', "expenses/requests/{$request()->id}/accept", $scope === 'hq' ? ['from_account' => Account::Company->value] : []);
            }, fn (): bool => $request()?->status === 'accepted');
        }
    }

    private function closes(): void
    {
        foreach (['2026-04' => '2026-05-01 08:00', '2026-05' => '2026-06-01 08:00', '2026-06' => '2026-07-01 08:00'] as $month => $at) {
            $this->ctx->timeline->at($at, "month-end close {$month}", function () use ($month): void {
                $finance = $this->ctx->staff('HQ_FIN2');
                $period = $this->ctx->api->call($finance, 'POST', 'accounting/periods', ['month' => $month]);
                $this->ctx->api->call($finance, 'POST', "accounting/periods/{$period['data']['id']}/close");
            }, fn (): bool => AccountingPeriod::where('company_id', $this->ctx->company->id)->whereDate('period_start', "{$month}-01")->where('status', AccountingPeriod::STATUS_CLOSED)->exists());
        }
    }

    private function commissionAndPayroll(): void
    {
        $run = fn (): ?PayrollRun => PayrollRun::where('company_id', $this->ctx->company->id)->whereDate('period', self::PAYROLL_PERIOD.'-01')->first();

        $this->ctx->timeline->at('2026-07-02 09:00', 'commission June 2026 calculated', function (): void {
            $this->ctx->api->call($this->ctx->staff('HQ_HR'), 'POST', 'hrm/commission/calculate', ['period' => self::PAYROLL_PERIOD]);
        }, fn (): bool => CommissionAllocation::whereIn('accounting_period_id', AccountingPeriod::where('company_id', $this->ctx->company->id)->whereDate('period_start', self::PAYROLL_PERIOD.'-01')->pluck('id'))->exists());

        // Spec §21 / §22: commission is paid through its own flow — HR requests, Finance approves and pays on its own date.
        $june = fn () => CommissionAllocation::whereIn('accounting_period_id', AccountingPeriod::where('company_id', $this->ctx->company->id)->whereDate('period_start', self::PAYROLL_PERIOD.'-01')->pluck('id'))->where('amount', '>', 0);
        $this->ctx->timeline->at('2026-07-02 09:30', 'commission June 2026 payment requested', function (): void {
            $this->ctx->api->call($this->ctx->staff('HQ_HR'), 'POST', 'hrm/commission/payments/request', ['period' => self::PAYROLL_PERIOD]);
        }, fn (): bool => ! $june()->whereIn('payment_status', [CommissionAllocation::STATUS_CALCULATED, CommissionAllocation::STATUS_AWAITING_REQUEST])->exists());
        $this->ctx->timeline->at('2026-07-04 09:00', 'commission June 2026 approved', function (): void {
            $this->ctx->api->call($this->ctx->staff('HQ_FIN1'), 'POST', 'hrm/commission/payments/approve', ['period' => self::PAYROLL_PERIOD]);
        }, fn (): bool => ! $june()->where('payment_status', CommissionAllocation::STATUS_REQUESTED)->exists());
        $this->ctx->timeline->at('2026-07-04 10:00', 'commission June 2026 paid', function (): void {
            $this->ctx->api->call($this->ctx->staff('HQ_FIN1'), 'POST', 'hrm/commission/payments/pay', ['period' => self::PAYROLL_PERIOD, 'ac_id' => 'company']);
        }, fn (): bool => ! $june()->where('payment_status', CommissionAllocation::STATUS_FINANCE_APPROVED)->exists());

        $this->ctx->timeline->at('2026-07-02 10:00', 'payroll June 2026 generated', function (): void {
            $this->ctx->api->call($this->ctx->staff('HQ_HR'), 'POST', 'hrm/payroll/generate', ['period' => self::PAYROLL_PERIOD]);
        }, fn (): bool => $run() !== null);

        $this->ctx->timeline->at('2026-07-03 09:00', 'payroll June 2026 approved', function () use ($run): void {
            $this->ctx->api->call($this->ctx->staff('HQ_HR'), 'POST', "hrm/payroll/{$run()->id}/approve");
        }, fn (): bool => $run() !== null && $run()->status !== PayrollRun::STATUS_DRAFT);

        $this->ctx->timeline->at('2026-07-03 11:00', 'salary liquidity floats', fn () => $this->salaryLiquidity($run()), fn (): bool => $run()?->status === PayrollRun::STATUS_PAID);

        $this->ctx->timeline->at('2026-07-03 14:00', 'payroll June 2026 paid', function () use ($run): void {
            $this->ctx->api->call($this->ctx->staff('HQ_FIN1'), 'POST', "hrm/payroll/{$run()->id}/pay", ['ac_id' => 'interest']);
        }, fn (): bool => $run()?->status === PayrollRun::STATUS_PAID);
    }

    /**
     * Finance moves money into each branch INTEREST A/C that pays salaries (Principal → Interest float, funded from the
     * company account when the branch principal is short) so no account goes below zero on the pay date.
     */
    private function salaryLiquidity(PayrollRun $run): void
    {
        $engine = app(PayrollEngine::class);
        $required = [];

        foreach ($run->items as $item) {
            $paying = $engine->payingAccount($item);
            if ($paying['account'] === Account::Interest) {
                $required[$paying['branch']] = ($required[$paying['branch']] ?? 0) + (float) $item->gross;
            }
        }

        foreach ($required as $branchId => $amount) {
            $this->ensureInterest($branchId, $amount);
        }
    }

    /**
     * Before a payment from a branch INTEREST A/C: when it holds less than needed, Finance floats the difference from the
     * branch PRINCIPAL A/C (topped up from the COMPANY ACCOUNT first when principal is short). Balances as of today.
     */
    private function ensureInterest(int $branchId, float $amount): void
    {
        $ledger = app(Ledger::class);
        $today = CarbonImmutable::today();
        $interest = $ledger->balance($this->ctx->company->id, Account::Interest, $branchId, until: $today);
        $shortfall = (int) (ceil(($amount + 50000 - $interest) / 10000) * 10000);
        if ($interest >= $amount || $shortfall <= 0) {
            return;
        }

        $finance = $this->ctx->staff('HQ_FIN1');
        $principal = $ledger->balance($this->ctx->company->id, Account::Principal, $branchId, until: $today);
        if ($principal < $shortfall) {
            $this->ctx->api->call($finance, 'POST', 'capital/floats', ['blanch_id' => $branchId, 'blanch_amount' => (int) (ceil(($shortfall - max(0, $principal)) / 100000) * 100000)]);
        }
        $this->ctx->api->call($finance, 'POST', 'capital/floats/accounts', ['blanch_id' => $branchId, 'from_acc' => 'PR', 'to_acc' => 'INT', 'amount' => $shortfall]);
        $this->ctx->log("  liquidity: {$shortfall} PRINCIPAL → INTEREST at branch {$branchId}");
    }
}
