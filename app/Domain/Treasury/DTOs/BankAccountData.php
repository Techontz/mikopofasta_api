<?php

declare(strict_types=1);

namespace App\Domain\Treasury\DTOs;

use App\Domain\Treasury\Enums\Currency;
use App\Enums\ActiveStatus;

/** Mirrors the frontend's `BankAccountInputSchema` — the Register Account form. */
final readonly class BankAccountData
{
    public function __construct(
        public string $bankName,
        public string $accountName,
        public string $accountNumber,
        public ?int $branchId,
        public Currency $currency,
        public string $openingBalance,
        public ActiveStatus $status,
        public ?string $description,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        $blankToNull = static function (mixed $v): ?string {
            $s = trim((string) ($v ?? ''));

            return $s === '' ? null : $s;
        };

        return new self(
            bankName: trim((string) $validated['bankName']),
            accountName: trim((string) $validated['accountName']),
            accountNumber: trim((string) $validated['accountNumber']),
            branchId: isset($validated['branchId']) ? (int) $validated['branchId'] : null,
            currency: Currency::from((string) $validated['currency']),
            // A string all the way to the DECIMAL column.
            openingBalance: (string) ($validated['openingBalance'] ?? '0'),
            status: ActiveStatus::from((string) $validated['status']),
            description: $blankToNull($validated['description'] ?? null),
        );
    }
}
