<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

/**
 * Mirrors the frontend's ACCOUNT_TYPES and `chart_of_accounts.type` (§2.7).
 *
 * The normal side is what makes a balance readable: an asset with more debits
 * than credits has a positive balance, a liability with more credits than
 * debits likewise. Getting this wrong flips the sign of every report.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';
    case Control = 'control';

    /**
     * True when the account increases on the debit side.
     *
     * Matches the frontend's DEBIT_NORMAL list exactly: assets and expenses.
     * Control accounts are treated credit-normal, as the frontend does.
     */
    public function isDebitNormal(): bool
    {
        return $this === self::Asset || $this === self::Expense;
    }

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Asset',
            self::Liability => 'Liability',
            self::Equity => 'Equity',
            self::Income => 'Income',
            self::Expense => 'Expense',
            self::Control => 'Control',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
