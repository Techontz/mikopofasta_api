<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\Gender;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\District;
use App\Models\Group;
use App\Models\InterestFormula;
use App\Models\LoanProduct;
use App\Models\Region;
use App\Models\RepaymentSchedule;
use App\Models\Street;
use App\Models\User;
use App\Models\Ward;
use Database\Seeders\Legacy\InferredLookups;
use Database\Seeders\Legacy\LegacySource;

/**
 * No lookup a form depends on is ever empty.
 *
 * An empty dropdown does not make the system cautious, it makes it unusable —
 * a registration form with no branches in it cannot be submitted at all. This
 * suite is the guard against a later change that removes seed data for good
 * reasons and leaves a screen dead.
 *
 * The companion rule lives in LegacyLookupTest: those tests assert the legacy
 * values are exactly right, these assert nothing is missing. Both have to hold.
 * Where a table has no legacy source at all, the values come from
 * InferredLookups and are marked as inferred there.
 */
it('leaves no Register Customer dropdown empty', function (): void {
    seedCustomerBook();
    test()->seed(Database\Seeders\LoanProductSeeder::class);
    test()->seed(Database\Seeders\LegacyCustomerSeeder::class);

    /*
     * Keyed by the label the officer sees on the form, so a failure names the
     * control that would be broken rather than a table.
     */
    $selects = [
        'Branch' => Branch::query()->count(),
        'Employee' => User::query()->count(),
        'Types of customer' => CustomerCategory::query()->count(),
        'Loan Type' => LoanProduct::query()->count(),
        'Region' => Region::query()->count(),
        'District' => District::query()->count(),
        'Ward' => Ward::query()->count(),
        'Street' => Street::query()->count(),
        'Customer' => Customer::query()->count(),
        'Group' => Group::query()->count(),
        'Repayment/Restoration Type' => RepaymentSchedule::query()->count(),
        'Interest Formula' => InterestFormula::query()->count(),
    ];

    foreach ($selects as $label => $count) {
        expect($count)->toBeGreaterThan(0, "the {$label} dropdown would render with no options");
    }

    // Gender comes from an enum rather than a table, so it cannot be seeded
    // empty — but it can be emptied by editing the enum, which this catches.
    expect(Gender::cases())->toHaveCount(2)
        ->and(array_map(fn (Gender $g): string => $g->value, Gender::cases()))
        ->toEqualCanonicalizing(['male', 'female']);
});

it('seeds every customer the legacy screens name', function (): void {
    seedCustomerBook();
    test()->seed(Database\Seeders\LegacyCustomerSeeder::class);

    $seeded = Customer::query()
        ->where('nida_number', 'like', InferredLookups::SYNTHETIC_NIDA_PREFIX.'%')
        ->get()
        ->map(fn (Customer $c): string => trim("{$c->first_name} {$c->middle_name} {$c->last_name}"));

    expect($seeded->all())
        ->toEqualCanonicalizing(array_column(LegacySource::customers(), 'name'));
});

it('keeps legacy customer names spelled exactly as the legacy system spells them', function (): void {
    seedCustomerBook();
    test()->seed(Database\Seeders\LegacyCustomerSeeder::class);

    /*
     * Two of the eighteen are lower case where the rest are upper, and three
     * carry what look like misspellings — JACKSPON, CHRIZESTOM, MPPOGOLE. A
     * person's name as the system records it is not a spelling error to be
     * swept, and normalising the casing would break the match against legacy
     * records just as surely.
     */
    expect(Customer::query()->where('first_name', 'tumaini')->exists())->toBeTrue()
        ->and(Customer::query()->where('last_name', 'JACKSPON')->exists())->toBeTrue()
        ->and(Customer::query()->where('first_name', 'CHRIZESTOM')->exists())->toBeTrue();
});

it('marks every inferred national ID so it can never pass as verified', function (): void {
    seedCustomerBook();
    test()->seed(Database\Seeders\LegacyCustomerSeeder::class);

    $legacy = Customer::query()
        ->where('nida_number', 'like', InferredLookups::SYNTHETIC_NIDA_PREFIX.'%')
        ->get();

    foreach ($legacy as $customer) {
        // A real Tanzanian NIDA number never opens with a run of zeroes, so
        // this prefix is what makes a synthetic identity findable later.
        expect($customer->nida_number)->toStartWith(InferredLookups::SYNTHETIC_NIDA_PREFIX)
            // And none of them is stamped as checked against the registry.
            ->and($customer->nida_verified_at)->toBeNull()
            ->and($customer->otp_verified_at)->toBeNull()
            ->and($customer->face_verified_at)->toBeNull();
    }
});

it('gives every branch a region and a phone, so scoping and branch edit both work', function (): void {
    seedOrganization();

    // Both are inferred — see InferredLookups — but both are load bearing: a
    // regional manager with no branch regions sees an empty branch list, and
    // the branch edit form will not submit without a phone.
    expect(Branch::query()->whereNull('region_id')->count())->toBe(0)
        ->and(Branch::query()->whereNull('phone')->count())->toBe(0);
});
