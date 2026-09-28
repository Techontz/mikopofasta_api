<?php

namespace App\Services;

use App\Enums\Account;
use App\Enums\HqFund;
use App\Models\BankAccount;
use Illuminate\Support\Str;

/**
 * The shareholders' (Investment) accounts money can be sent TO: the rows of the owners' Company Account List that hold
 * real money — the COMPANY ACCOUNT, each bank account, the Investment RESERVE A/C and the DIVIDEND A/C (user request
 * 2026-09-22). "Assets" is never offered: a fixed asset is not money (user ruling 2026-09-16, the same rule the float
 * and capital forms follow).
 *
 * DIVIDEND A/C is the credit-normal DIVIDEND ACCOUNT the owners' modal shows as its Dividend memo, so sending to it
 * settles dividends already declared: the money leaves the HQ row and the dividend owed falls by the same amount. It
 * does not record who was paid — a shareholder's own entitlement is paid through Dividends → Pay ({@see DividendService}).
 *
 * Only names are published, never balances: HQ and Finance never see the Investment (user ruling, stated twice on
 * 2026-09-16) — they may send money to an account of it without being told what is in it.
 */
class ShareholderAccounts
{
    /** A bank destination is "bank:{id}", since every bank account shares one ledger account key. */
    private const BANK_PREFIX = 'bank:';

    /**
     * @return list<array{value: string, label: string, fund: string|null}>
     */
    public function options(int $companyId): array
    {
        $options = [['value' => Account::Company->value, 'label' => 'Company A/C']];

        foreach (BankAccount::where('company_id', $companyId)->orderBy('id')->get() as $bankAccount) {
            $options[] = ['value' => self::BANK_PREFIX.$bankAccount->id, 'label' => $bankAccount->name];
        }

        $options[] = ['value' => Account::InvestmentReserve->value, 'label' => 'Reserve A/C'];
        $options[] = ['value' => Account::DividendPayable->value, 'label' => 'Dividend A/C'];

        return array_map(fn (array $option): array => $option + ['fund' => $this->fundNamed($option['label'])], $options);
    }

    /**
     * @return list<string>
     */
    public function values(int $companyId): array
    {
        return array_column($this->options($companyId), 'value');
    }

    /**
     * The ledger destination a stored to_account (+ to_bank_account_id) points at, as {@see Ledger::journal()} lines take it.
     *
     * @return array{account: Account, bank?: int}
     */
    public function destination(string $account, ?int $bankAccountId): array
    {
        return $bankAccountId === null
            ? ['account' => Account::from($account)]
            : ['account' => Account::Bank, 'bank' => $bankAccountId];
    }

    /**
     * Split a dropdown value into the columns hq_transactions stores.
     *
     * @return array{to_account: string, to_bank_account_id: int|null}
     */
    public function columns(string $value): array
    {
        return str_starts_with($value, self::BANK_PREFIX)
            ? ['to_account' => Account::Bank->value, 'to_bank_account_id' => (int) Str::after($value, self::BANK_PREFIX)]
            : ['to_account' => $value, 'to_bank_account_id' => null];
    }

    /**
     * What the transaction list prints for a destination that is not a bank account (a bank prints its own name).
     */
    public static function nameOf(string $account): ?string
    {
        return match (Account::tryFrom($account)) {
            Account::Company => 'Company A/C',
            Account::InvestmentReserve => 'Reserve A/C',
            Account::DividendPayable => 'Dividend A/C',
            default => null,
        };
    }

    /**
     * The HQ row a shareholder account is named after, if any: choosing it on the FROM side fills this account in on the
     * TO side. RESERVE ↔ Reserve A/C and DIVIDEND ↔ Dividend A/C are the pairs the two lists share, and for those two the
     * pair is the only destination allowed ({@see HqFund::onlyDestination()}); the comparison is by name, so a bank
     * account named after an HQ row would pair too, and nothing pairs when no name matches.
     */
    private function fundNamed(string $label): ?string
    {
        $normalise = fn (string $name): string => trim(preg_replace('/\b(A\/C|ACCOUNT)\b/i', '', Str::upper($name)) ?? '');

        foreach (HqFund::sources() as $fund) {
            if ($normalise($fund->label()) === $normalise($label)) {
                return $fund->value;
            }
        }

        return null;
    }
}
