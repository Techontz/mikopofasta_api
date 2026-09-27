<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\AccessControl;
use App\Services\DashboardStatistics;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class DashboardController extends ApiController
{
    /**
     * Dashboard figures (live admin/index): account header, stat cards, today's summary, account modals, customer-type
     * table and the Finance dashboard KPIs. Every figure and total is computed here; the web app only displays them.
     *
     * Company money (Company A/C + bank accounts, capital, HQ and fund account balances, float sent to HQ) is only sent to
     * employees whose role covers the whole company; branch- and zone-scoped employees get null, whatever their permissions,
     * and for them the green card is their branch PETTY CASH A/C with every other figure covering their branches only.
     *
     * The Investment (the owners' position: Company A/C, banks, Investment reserve, assets) needs capital.view: HQ and Finance
     * see HQ funds instead — the PRINCIPAL A/C the company floated to HQ plus the HQ income pools.
     */
    public function __invoke(DashboardStatistics $statistics, AccessControl $access): JsonResponse
    {
        $this->authorizeAny('dashboard.view');

        $company = $this->currentCompany();
        $today = CarbonImmutable::today();
        $employee = $this->currentEmployee();
        $branchIds = $access->branchIds($employee);
        $seesCompanyMoney = $branchIds === null;
        $showFinance = $seesCompanyMoney && ($employee->can('accounting.view') || $employee->can('capital.view'));
        $investment = $seesCompanyMoney && $employee->can('capital.view');
        $accountBalances = match (true) {
            $investment => $statistics->accountBalances($company),
            $showFinance => $statistics->hqFunds($company),
            default => null,
        };

        return response()->json(['data' => [
            'header_accounts' => $showFinance ? $statistics->headerAccounts($company) : null,
            'cards' => $statistics->cards($company, $today, $branchIds, $investment),
            'account_balances' => $accountBalances,
            'account_balances_total' => match (true) {
                $accountBalances === null => null,
                $investment => round(array_sum($accountBalances), 2),
                default => $statistics->hqFundsTotal($company, $accountBalances),
            },
            // The HQ Account List shows only its six accounts; the memo lines belong to the owners' Company Account List.
            'account_memos' => $investment ? $statistics->accountMemos($company, $branchIds) : null,
            'branch_accounts' => $showFinance ? $statistics->branchAccounts($company, $today) : null,
            'operating_income' => $showFinance ? $statistics->operatingIncome($company) : null,
            // Capital received is shareholders' money (the Investment): owners only, never HQ or Finance.
            'today' => array_merge($statistics->today($company, $today, $branchIds), $investment ? [] : ['capital_received' => null]),
            'finance_kpis' => $statistics->financeKpis($employee, [
                'penalty' => $employee->can('penalties.manage'),
                'salary_advance' => $employee->can('salary_advance.manage'),
                'hq_accounts' => $seesCompanyMoney && $employee->can('hq.manage'),
                'company_accounts' => $investment,
                'account_balance' => $seesCompanyMoney,
            ]),
            'customer_types' => collect($statistics->customerTypes($company, $branchIds))->map(fn (array $row): array => collect($row)->except('customers')->all())->values(),
        ]]);
    }
}
