<?php

declare(strict_types=1);

namespace App\Domain\Treasury\DTOs;

use App\Domain\Treasury\Enums\PayMethod;

/**
 * Input for recording capital — Capital → Add Capitals.
 * The legacy form's five fields, in its order, then the money trail: which
 * company account received it, the transaction reference, and the
 * shareholder's own account it came from.
 */
final readonly class CapitalContributionData
{
    public function __construct(
        public int $shareholderId,
        public string $amount,
        public PayMethod $payMethod,
        public ?string $receiptNo,
        public ?string $chequeNo,
        public ?int $bankAccountId = null,
        public ?string $reference = null,
        public ?string $sourceAccountName = null,
        public ?string $sourceAccountNumber = null,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        $blankToNull = static function (mixed $v): ?string {
            $s = trim((string) ($v ?? ''));

            return $s === '' ? null : $s;
        };

        return new self(
            shareholderId: (int) $validated['shareholderId'],
            // A string all the way to the column: money does not pass through
            // a float on its way to a DECIMAL.
            amount: (string) $validated['amount'],
            payMethod: PayMethod::from((string) $validated['payMethod']),
            receiptNo: $blankToNull($validated['receiptNo'] ?? null),
            chequeNo: $blankToNull($validated['chequeNo'] ?? null),
            bankAccountId: isset($validated['bankAccountId']) ? (int) $validated['bankAccountId'] : null,
            reference: $blankToNull($validated['reference'] ?? null),
            sourceAccountName: $blankToNull($validated['sourceAccountName'] ?? null),
            sourceAccountNumber: $blankToNull($validated['sourceAccountNumber'] ?? null),
        );
    }
}
