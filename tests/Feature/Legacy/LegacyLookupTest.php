<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Group;
use App\Models\HqAccount;
use App\Support\Money;
use Database\Seeders\HqAccountSeeder;
use Database\Seeders\Legacy\LegacySource;

/**
 * The seeded lookups match the legacy system, and nothing has crept back in.
 *
 * These tests are unusual in that they assert on seed data rather than on
 * behaviour. That is deliberate: the failure they exist to catch is somebody —
 * very plausibly me, on a later pass — adding a helpful-looking extra branch or
 * a fourth restoration type, which no behavioural test would ever notice.
 *
 * Every expectation below is a value read off a legacy screenshot. If one of
 * these fails, either the seed drifted or the legacy system changed; in both
 * cases the transcription in LegacySource is what to check first.
 */
it('seeds exactly the branches the legacy system shows, plus head office', function (): void {
    seedOrganization();

    $names = Branch::query()->where('is_head_office', false)->pluck('name');

    // TestOrgScaffoldSeeder adds Lindi for the §12 hierarchy tests; it is not a
    // legacy branch and is excluded here rather than silently tolerated.
    expect($names->reject(fn (string $n): bool => $n === 'Lindi')->values()->all())
        ->toEqualCanonicalizing(LegacySource::branches());
});

it('spells the legacy branch names exactly, casing included', function (): void {
    seedOrganization();

    // NEW KALENGE is upper case in the legacy data where the other two are
    // title case. A well-meaning "New Kalenge" would pass a case-insensitive
    // comparison and be wrong.
    $stored = Branch::query()->where('name', 'like', '%KALENGE%')->value('name');

    expect($stored)->toBe('NEW KALENGE');
});

it('seeds the seven headquarters accounts and nothing else', function (): void {
    seedRbac();
    test()->seed(HqAccountSeeder::class);

    $accounts = HqAccount::query()->orderBy('id')->get();

    expect($accounts->pluck('name')->all())->toBe(array_column(LegacySource::hqAccounts(), 'name'))
        ->and($accounts)->toHaveCount(7);
});

it('reproduces the headquarters balances down to the printed total', function (): void {
    seedRbac();
    test()->seed(HqAccountSeeder::class);

    $sum = Money::sum(HqAccount::query()->get()->map(fn (HqAccount $a): Money => $a->balance()));

    // 8,667,270 is what the legacy balance screen prints in its TOTAL row.
    expect($sum->toDecimalString())->toBe(LegacySource::hqAccountsTotal())
        ->and($sum->toDecimalString())->toBe('8667270.00');
});

it('seeds one group, named WAZURI', function (): void {
    seedCustomerBook();

    // The legacy Group List reads "Showing 1 to 1 of 1 entries". Thirty seeded
    // groups was an earlier misreading of the brief; this is the correction.
    expect(Group::query()->pluck('name')->all())->toBe(['WAZURI']);
});

it('keeps a standing record of which lookups are still unknown', function (): void {
    /*
     * Not a formality. An empty dropdown in this system is either a bug or an
     * uncaptured legacy screen, and the difference matters enough to be
     * written down where it cannot be lost. If a lookup here is later filled
     * in from a real capture, its entry should be deleted in the same change.
     */
    $unobserved = LegacySource::unobserved();

    expect($unobserved)->not->toBeEmpty()
        ->and($unobserved)->toHaveKeys(['employees', 'loanTypes', 'customerTypes', 'regions', 'gender']);

    foreach ($unobserved as $lookup => $whatWouldSettleIt) {
        expect($whatWouldSettleIt)->not->toBeEmpty("{$lookup} must say what capture would settle it");
    }
});
