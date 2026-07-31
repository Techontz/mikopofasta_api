<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Enums;

/**
 * Which of the three float screens raised a transfer.
 *
 * All three move money between two ledger accounts; they differ in which
 * accounts, and in whether a second person has to agree first.
 */
enum FloatTransferKind: string
{
    case CompanyToBranch = 'company_to_branch';
    case BranchToBranch = 'branch_to_branch';
    case AccountToAccount = 'account_to_account';

    /**
     * Only branch-to-branch waits for approval. The other two are one person
     * moving the company's own money between its own accounts, and the legacy
     * screens show no status for either.
     */
    public function requiresApproval(): bool
    {
        return $this === self::BranchToBranch;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
