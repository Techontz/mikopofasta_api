<?php

declare(strict_types=1);

namespace App\Domain\Organization\Services;

use App\Models\Branch;
use Illuminate\Support\Str;

/**
 * Derives a branch code when nobody supplied one.
 *
 * The code is a segment of every customer payment reference the branch issues
 * (`MF-YYYY-BRANCHCODE-000001`), so the preferred path is an administrator
 * choosing it deliberately — it is printed on receipts and read aloud down a
 * phone. This exists for the cases where one was not chosen: the seeders, the
 * factories, and an API client that posts a branch without one.
 *
 * ## Why derived rather than required
 *
 * Making the field mandatory would have been simpler, and wrong twice over: it
 * would break every existing client that posts a branch today, and it would
 * leave a branch un-creatable at the moment somebody most needs one. A derived
 * code is always correctable afterwards; a rejected request is not.
 *
 * ## The rule
 *
 * Initials for a multi-word name (Head Office → HO), first three letters
 * otherwise (Mwanza → MWA), ASCII-folded so a diacritic never produces a code
 * that cannot be typed at a till, and suffixed on collision. It deliberately
 * matches the backfill in `2026_08_24_000001_add_code_to_branches`, which keeps
 * its own copy: a migration must behave the same in a year's time as it did the
 * day it ran, and it cannot do that if it calls into code that is still moving.
 */
final class BranchCodeGenerator
{
    private const int MAX_LENGTH = 12;

    /**
     * A unique code for this name, ignoring one branch's own existing code.
     *
     * `$ignoreId` is for updates: renaming a branch should be able to re-derive
     * a code without colliding with the code that branch already holds.
     */
    public function forName(string $name, ?int $ignoreId = null): string
    {
        $base = $this->derive($name);
        $candidate = $base;
        $suffix = 2;

        while ($this->taken($candidate, $ignoreId)) {
            $candidate = substr($base, 0, self::MAX_LENGTH - strlen((string) $suffix)).$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function taken(string $code, ?int $ignoreId): bool
    {
        return Branch::query()
            ->withTrashed()
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    private function derive(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 ]/', '', Str::ascii($name)) ?? '';
        $words = array_values(array_filter(explode(' ', $clean), fn (string $w): bool => $w !== ''));

        if ($words === []) {
            return 'BR';
        }

        $code = count($words) > 1
            ? implode('', array_map(fn (string $w): string => strtoupper($w[0]), array_slice($words, 0, 4)))
            : strtoupper(substr($words[0], 0, 3));

        return substr($code, 0, self::MAX_LENGTH);
    }
}
