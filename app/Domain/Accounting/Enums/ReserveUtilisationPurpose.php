<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * What the Reserve fund is being released for.
 *
 * The client's meeting summary names three uses and no others: "Reserve
 * inatumikaje? Inaweza kurudi kwa njia ya mtaji, labda kutengeneza/kufungua
 * branch mpya, au kuweka sehemu nyingine" — how is the reserve used? It can
 * return BY WAY OF CAPITAL, perhaps to open a new branch, or to set up another
 * department.
 *
 * ## Why every purpose posts the same way
 *
 * "Kurudi kwa njia ya mtaji" is the whole accounting model in one phrase, and
 * it is worth being explicit about why.
 *
 * The Reserve is a CONTROL account (§5 types it so), not a bank balance. No
 * cash was ever moved into it — the close posts `Dr Profit · Cr Reserve`, which
 * reserves a portion of equity rather than segregating money. The cash the
 * business holds sits in its bank accounts the entire time.
 *
 * So releasing reserve cannot move cash, because there is no cash in it to
 * move. What a release does is UN-RESERVE equity: `Dr Reserve · Cr Capital`.
 * The branch or department is then funded out of capital and spends through the
 * ordinary expense path, where it belongs and where the expense reports will
 * find it.
 *
 * An earlier draft let the requester name a destination account and credited it
 * directly. That was wrong twice over: crediting a bank account DECREASES it
 * (assets are debit-normal), so the release drained the very account it claimed
 * to fund; and it implied the reserve held cash it never held.
 *
 * The purpose is therefore recorded for the audit trail and the reserve
 * utilisation report — D1 requires every movement to be fully audited, and
 * "why" is the part that matters — while the posting stays uniform.
 */
enum ReserveUtilisationPurpose: string
{
    case ReturnToCapital = 'return_to_capital';
    case NewBranch = 'new_branch';
    case NewDepartment = 'new_department';
    case Other = 'other';

    /**
     * Whether the requester must name the branch this release is for.
     *
     * Only opening a branch does. It is recorded for reporting, not for
     * posting: the credit goes to Capital either way, and a branch that does
     * not exist yet has no ledger dimension to carry.
     */
    public function requiresTargetBranch(): bool
    {
        return $this === self::NewBranch;
    }

    public function label(): string
    {
        return match ($this) {
            self::ReturnToCapital => 'Return to Capital',
            self::NewBranch => 'Open New Branch',
            self::NewDepartment => 'Start New Department',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
