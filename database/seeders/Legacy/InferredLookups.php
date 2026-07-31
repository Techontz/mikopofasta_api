<?php

declare(strict_types=1);

namespace Database\Seeders\Legacy;

use App\Domain\Customers\Enums\Gender;

/**
 * INFERRED lookup data — NOT transcribed from the legacy system.
 *
 * The companion to LegacySource, and deliberately a separate file so the two
 * can never be confused. The rule across both:
 *
 *   LegacySource   — read off a legacy screen. Evidence.
 *   InferredLookups — reasoned out so the application is usable. Not evidence.
 *
 * Everything here exists because a form cannot function without it. A branch
 * write requires a phone number; regional scoping requires a branch to sit in a
 * region; a customer row requires a date of birth whether or not anybody has
 * told us one. Leaving those empty does not make the system honest, it makes it
 * unusable — so they are filled, and every one of them says here that it was
 * filled rather than found.
 *
 * Two properties every value below is chosen to have:
 *
 *   1. It is plausible enough that the application works normally.
 *   2. It is identifiable as inferred once you know to look — synthetic
 *      identifiers carry an obvious marker rather than mimicking real ones.
 *
 * When a real capture arrives, the matching entry here should be deleted in the
 * same change that adds it to LegacySource.
 */
final class InferredLookups
{
    /**
     * The marker every synthetic national ID starts with.
     *
     * Real Tanzanian NIDA numbers are 20 digits and never begin with a run of
     * zeroes, so a number starting here is recognisable as ours at a glance and
     * in a `LIKE` query. This matters more than it looks: a NIDA number is a
     * KYC field people are entitled to trust, and a synthetic one that looked
     * real would be indistinguishable from a verified identity forever.
     */
    public const string SYNTHETIC_NIDA_PREFIX = '00000000';

    /**
     * Likewise for phone numbers we had to invent.
     *
     * 0700 000 0xx is not a live Tanzanian mobile range, so nobody reaches a
     * stranger by dialling one out of a report.
     */
    public const string SYNTHETIC_PHONE_PREFIX = '0700000';

    /**
     * The date of birth given to a legacy customer whose real one is unknown.
     *
     * INFERRED. A single sentinel date rather than a spread of plausible ones,
     * on purpose: eighteen customers all born on 1 January 1990 is obviously
     * placeholder data the moment anyone looks at the customer list, whereas
     * eighteen convincing birthdays would read as fact. The Age column will show
     * them all the same age, which is the point.
     */
    public const string PLACEHOLDER_DOB = '1990-01-01';

    /**
     * Which region each branch sits in.
     *
     * INFERRED. No captured legacy screen shows a branch's region — the branch
     * list screen has never been captured at all. These are reasoned from the
     * branch names against Tanzanian administrative geography: Missenyi is a
     * district of Kagera, Kakonko a district of Kigoma, and Kalenge is a ward in
     * Kigoma's Kasulu district. All three therefore sit in the north-west lake
     * zone, which is coherent for one lender's branch network.
     *
     * (An earlier version of this put NEW KALENGE in Mbeya, which is the far
     * south-west and made no geographic sense. That was invented rather than
     * reasoned, which is the difference this file is trying to hold on to.)
     *
     * Regional-manager scoping (§13) reads this, so leaving it null made a
     * regional manager see no branches at all.
     *
     * @return array<string, string>
     */
    public static function branchRegions(): array
    {
        return [
            'Head Office' => 'Mwanza',
            'NEW KALENGE' => 'Kigoma',
            'Missenyi' => 'Kagera',
            'Kakonko' => 'Kigoma',
        ];
    }

    /**
     * A contact number per branch.
     *
     * INFERRED, and visibly so — see SYNTHETIC_PHONE_PREFIX. Branch writes
     * require a phone, so a null here makes the branch edit form unsubmittable.
     *
     * @return array<string, string>
     */
    public static function branchPhones(): array
    {
        return [
            'Head Office' => '07000000001',
            'NEW KALENGE' => '07000000002',
            'Missenyi' => '07000000003',
            'Kakonko' => '07000000004',
        ];
    }

    /**
     * Gender for the eighteen legacy customers.
     *
     * INFERRED from the given name, because `customers.gender` is NOT NULL and
     * every customer list and report groups on it.
     *
     * This is the least certain inference in the file and is worth naming as
     * such: it reads a Swahili/Tanzanian given name and guesses. Asha, Zuena,
     * Catherini, Rebecca, Aisha and Jacline are conventionally female; the rest
     * are conventionally male or ambiguous, and ambiguous defaults to male only
     * because the column demands one of two values. Any of these may be wrong
     * about a real person, and all eighteen should be corrected from the All
     * Customer screen as soon as it is captured with rows in it.
     *
     * @return array<string, Gender>
     */
    public static function customerGenders(): array
    {
        return [
            'ASHA Z JUMA' => Gender::Female,
            'ZUENA E HASAN' => Gender::Female,
            'CATHERINI D NTABA' => Gender::Female,
            'REBECCA P MPPOGOLE' => Gender::Female,
            'AISHA M MTWEE' => Gender::Female,
            'JACLINE P NDILASELA' => Gender::Female,
        ];
    }

    /**
     * The gender assumed when the name gives nothing to go on.
     *
     * Present as a named constant rather than inline so that the assumption is
     * visible in one place and can be changed in one place.
     */
    public static function defaultGender(): Gender
    {
        return Gender::Male;
    }

    /**
     * A synthetic national ID for the nth legacy customer.
     *
     * Deterministic, so re-running the seeder does not produce a second copy of
     * everybody, and prefixed so it is never mistaken for a verified identity.
     */
    public static function syntheticNida(int $index): string
    {
        return self::SYNTHETIC_NIDA_PREFIX.str_pad((string) $index, 12, '0', STR_PAD_LEFT);
    }

    /**
     * A synthetic phone number for a legacy customer whose real one is unknown.
     *
     * Only eight of the eighteen need this: the Loan Pending Approve screen
     * carries real phone numbers for the other ten, and those are used instead.
     */
    public static function syntheticPhone(int $index): string
    {
        return self::SYNTHETIC_PHONE_PREFIX.str_pad((string) $index, 4, '0', STR_PAD_LEFT);
    }
}
