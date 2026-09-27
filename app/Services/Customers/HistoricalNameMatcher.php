<?php

namespace App\Services\Customers;

/**
 * Decides when a name printed on one old-system report is the same person as a name printed on another, or as a
 * customer already in the system.
 *
 * The printouts abbreviate differently: a File report prints "SHABAN D. MASONJO" and "ALFONCE TEST G. SINKONGE"
 * where a Penalty report prints "SHABAN DAUD MASONJO" and "ALFONCE TEST GOTROP SINKONGE". So a name is read as a
 * surname (the last word) plus the given names before it, and two names are the same person when the surnames are
 * equal and the given names line up — an initial standing for a full name that begins with it.
 *
 * Only a single letter may abbreviate a word. Two spelled-out words must be equal, so "MARIAM" is not "MARIAMU" and
 * "DOTO" is not "DOTTO": those are left for a person to decide, never merged automatically.
 */
final class HistoricalNameMatcher
{
    /**
     * "ALFONCE TEST G. SINKONGE" → ['ALFONCE', 'TEST', 'G', 'SINKONGE']; the last word is the surname. Dots, case and
     * repeated spaces are ignored, and a lone "." (a blank initial on the printout) is dropped.
     *
     * @return list<string>
     */
    public static function parts(string $name): array
    {
        $words = preg_split('/\s+/', trim((string) preg_replace('/[^A-Z0-9\' ]/', ' ', strtoupper($name)))) ?: [];

        return array_values(array_filter($words, fn (string $word): bool => $word !== ''));
    }

    /**
     * Surname plus first given name, e.g. "SINKONGE|ALFONCE" — the bucket to look a person up in before comparing
     * the rest of the name with {@see same()}.
     */
    public static function key(string $name): string
    {
        $parts = self::parts($name);
        $surname = array_pop($parts) ?? '';

        return $surname.'|'.($parts[0] ?? '');
    }

    /**
     * Whether two printed names are the same person: same surname, and given names that line up in order, an initial
     * matching a name that starts with it. A name with fewer given names matches one with more only when the ones it
     * does have come first ("RIZIKI JORAM" can be "RIZIKI AMANI JORAM") — that is deliberately loose, so callers
     * accept it only when exactly one candidate matches.
     */
    public static function same(string $a, string $b): bool
    {
        $left = self::parts($a);
        $right = self::parts($b);
        if (array_pop($left) !== array_pop($right) || $left === [] || $right === []) {
            return false;
        }

        foreach (array_slice($left, 0, min(count($left), count($right))) as $index => $word) {
            if (! self::wordsAgree($word, $right[$index])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Two given names agree when they are the same word, or one is a single letter and the other begins with it.
     */
    private static function wordsAgree(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return (strlen($a) === 1 && str_starts_with($b, $a)) || (strlen($b) === 1 && str_starts_with($a, $b));
    }
}
