<?php

namespace App\Enums;

use App\Services\Approvals\ReserveProtection;
use App\Services\DashboardStatistics;

/**
 * The money rows of the HQ Account List — what Finance sees behind the green card on the dashboard and on Headquarters
 * Transaction → Hq Account balance ({@see DashboardStatistics::hqFunds()}).
 *
 * A row is not always one ledger account: OPERATION INCOME is the interest, loan fee and penalty pools of every branch
 * plus HQ's own, and RESERVE is every branch RESERVE A/C plus the HQ one. Money is drawn from such a row in proportion
 * to what each account in it holds, exactly as the reserve transfer does — the per-branch figure is only a report of
 * what that branch generated, never a separate pot.
 */
enum HqFund: string
{
    case OperationPrincipal = 'operation_principal';
    case OperationIncome = 'operation_income';
    case Fund = 'fund';
    case Reserve = 'reserve';
    case Dividend = 'dividend';

    public function label(): string
    {
        return match ($this) {
            self::OperationPrincipal => 'OPERATION PRINCIPAL',
            self::OperationIncome => 'OPERATION INCOME',
            self::Fund => 'FUND',
            self::Reserve => 'RESERVE',
            self::Dividend => 'DIVIDEND',
        };
    }

    /**
     * The ledger accounts the row's money is held in. DIVIDEND is what the owners are owed, and that money sits in the
     * OPERATION INCOME accounts until it is paid ({@see DashboardStatistics::hqFunds()}), so it is drawn from there.
     *
     * @return list<Account>
     */
    public function accounts(): array
    {
        return match ($this) {
            self::OperationPrincipal => [Account::Principal],
            self::OperationIncome, self::Dividend => [Account::Interest, Account::HqInterest, Account::LoanFee, Account::HqLoanFee, Account::Penalty, Account::HqPenalty],
            self::Fund => [Account::StaffFundCash],
            self::Reserve => [Account::Reserve, Account::HqReserve],
        };
    }

    /**
     * The account whose balance IS the row, when the row is a claim on money held elsewhere rather than the cash itself:
     * DIVIDEND is DIVIDEND PAYABLE — dividends declared and not yet paid — so that, not the income pool, is both the
     * figure Finance sees and the most that may be sent.
     */
    public function claim(): ?Account
    {
        return match ($this) {
            self::Dividend => Account::DividendPayable,
            default => null,
        };
    }

    /**
     * The one shareholders' (Investment) account this row may be sent to, when it may go to only one:
     *  - RESERVE → Investment RESERVE A/C: the single movement rule 3 allows out of the interest reserve;
     *  - DIVIDEND → DIVIDEND A/C: sending settles the declared dividend, which is what the row is.
     * The other rows are cash and may go to any of the owners' accounts.
     */
    public function onlyDestination(): ?Account
    {
        return match ($this) {
            self::Reserve => Account::InvestmentReserve,
            self::Dividend => Account::DividendPayable,
            default => null,
        };
    }

    /**
     * The rows Finance may send FROM on a Headquarters Transaction — every money row of his green card (user ruling
     * 2026-09-22, which added RESERVE and DIVIDEND). RESERVE still leaves HQ only towards the Investment RESERVE A/C and
     * only the owners may approve it ({@see ReserveProtection}, {@see onlyDestination()}).
     *
     * @return list<self>
     */
    public static function sources(): array
    {
        return [self::OperationPrincipal, self::OperationIncome, self::Fund, self::Reserve, self::Dividend];
    }
}
