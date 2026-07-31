<?php

declare(strict_types=1);

namespace App\Domain\Expenses\DTOs;

use App\Domain\Expenses\Enums\ExpenseScope;

/**
 * Input for filing an expense — the four-field form the claim screens show.
 * Mirrors the frontend's `ExpenseClaimInputSchema`, plus the branch and date
 * the screen supplies from context rather than from the form.
 */
final readonly class ExpenseRequestData
{
    public function __construct(
        public int $categoryId,
        public ?int $branchId,
        public string $amount,
        public string $description,
        public ?string $comment,
        public ?string $requestedOn,
        /**
         * The register the caller believes it is filing under, when it said so.
         * Checked against the category, never used to set anything.
         */
        public ?ExpenseScope $expectedScope = null,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        $blankToNull = static function (mixed $v): ?string {
            $s = trim((string) ($v ?? ''));

            return $s === '' ? null : $s;
        };

        return new self(
            categoryId: (int) $validated['expenseCategoryId'],
            branchId: isset($validated['branchId']) ? (int) $validated['branchId'] : null,
            // A string all the way to the DECIMAL column — money never passes
            // through a float on its way to the ledger.
            amount: (string) $validated['amount'],
            description: trim((string) $validated['description']),
            comment: $blankToNull($validated['comment'] ?? null),
            requestedOn: $blankToNull($validated['requestedOn'] ?? null),
            expectedScope: isset($validated['scope'])
                ? ExpenseScope::from((string) $validated['scope'])
                : null,
        );
    }
}
