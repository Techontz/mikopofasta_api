<?php

declare(strict_types=1);

namespace App\Domain\Customers\Support;

use App\Domain\Customers\Enums\MaritalStatus;
use App\Models\MasterData\MaritalStatusOption;

/**
 * Keeps `customers.marital_status` in step with `customers.marital_status_id`.
 *
 * ONE FACT, TWO COLUMNS, AND ONLY ONE OF THEM WAS BEING WRITTEN. The
 * registration form asks for marital status through the customer type's
 * configured field, which draws on the admin-managed `marital_statuses` list
 * and therefore writes the FOREIGN KEY. The customer profile — and the
 * reporting that predates that list — reads the ENUM column. So an officer
 * chose "Married", the answer was stored, and the profile showed a dash,
 * because the two columns are the same fact and nothing connected them.
 *
 * The list is the source of truth: an administrator owns it, can rename its
 * entries and can add to it. The enum is derived from whatever was chosen, by
 * CODE — `MARRIED` → `married` — never by the display name, so renaming
 * "Married" to "Ndoa" changes what the officer reads and not what is stored.
 *
 * A CODE WITH NO MATCHING ENUM VALUE LEAVES THE ENUM NULL, deliberately. An
 * institution may add "Separated" tomorrow; the column's four values are fixed
 * by a migration and cannot grow to meet it. Null is then the truthful answer
 * for a column that has no way to express the choice — and the profile falls
 * back to naming the chosen list entry, so the officer still reads what was
 * recorded. Widening the enum is a migration somebody should make deliberately,
 * not something a write path should fake around.
 */
final class MaritalStatusMirror
{
    /**
     * The enum value implied by a chosen list entry, or null when there is
     * none — because nothing was chosen, the row has gone, or its code names a
     * status this column cannot hold.
     */
    public static function forOption(int|string|null $maritalStatusId): ?string
    {
        if ($maritalStatusId === null || $maritalStatusId === '') {
            return null;
        }

        $code = MaritalStatusOption::query()->whereKey($maritalStatusId)->value('code');

        if (! is_string($code)) {
            return null;
        }

        return MaritalStatus::tryFrom(mb_strtolower($code))?->value;
    }

    /**
     * What to store for the enum column, given both halves of the payload.
     *
     * An explicit `maritalStatus` wins: a caller that names the enum directly
     * — the older clients, and the import path — is answering this question
     * itself and must not be second-guessed. Otherwise it is derived.
     *
     * @param array<string, mixed> $payload
     */
    public static function resolve(array $payload): ?string
    {
        $explicit = $payload['maritalStatus'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return self::forOption($payload['maritalStatusId'] ?? null);
    }
}
