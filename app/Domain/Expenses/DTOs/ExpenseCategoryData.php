<?php

declare(strict_types=1);

namespace App\Domain\Expenses\DTOs;

use App\Domain\Expenses\Enums\ExpenseScope;

/**
 * Input for the expense register — the one-field form behind "Add Expense".
 * Mirrors the frontend's `ExpenseNameInputSchema`.
 */
final readonly class ExpenseCategoryData
{
    public function __construct(
        public string $name,
        public ExpenseScope $scope,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: trim((string) $validated['name']),
            scope: ExpenseScope::from((string) $validated['scope']),
        );
    }
}
