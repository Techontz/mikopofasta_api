<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Enums\GuarantorRelationship;
use App\Domain\Customers\Enums\KycStatus;
use App\Domain\Customers\Enums\MaritalStatus;
use App\Domain\Customers\Enums\ResidenceType;
use App\Domain\Customers\Services\CustomerNumberGenerator;
use App\Domain\Customers\Services\NidaRegistry;
use App\Enums\ActiveStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\District;
use App\Models\Group;
use App\Models\Region;
use App\Models\Street;
use App\Models\User;
use App\Models\Ward;
use Database\Seeders\Legacy\LegacySource;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * A working customer book. NOT the legacy customer book.
 *
 * This is worth being blunt about, because the distinction is easy to lose: the
 * people seeded here are invented. They exist so the system is operable and
 * testable — so the loan module has borrowers, the ledger has postings and the
 * list filters have something to filter — and not one of them is a claim about
 * anybody the real business lends to.
 *
 * Eighteen real legacy customers ARE known, and they are transcribed in
 * LegacySource::customers(). They are deliberately not seeded, because seeding
 * them is impossible without inventing exactly what this exercise is meant to
 * stop inventing. Our `customers` table requires a NIDA number (NOT NULL and
 * UNIQUE), a date of birth and a gender for every row. The captured legacy
 * screens give a name, a branch, and for ten of the eighteen a phone number —
 * nothing more. The All Customer screen would have supplied the rest, and it
 * was captured with no rows in it.
 *
 * Fabricating a national ID number to satisfy a NOT NULL constraint would be
 * the worst version of the problem, not a workaround for it: it is
 * indistinguishable from a real one, it is unique so nothing ever collides to
 * reveal it, and it would sit in a KYC field that people are entitled to trust.
 * The eighteen stay transcribed and unseeded until a capture of the All
 * Customer list supplies the missing fields.
 *
 * For the invented book below, identities come from NidaRegistry rather than
 * being hand-written, so every seeded customer's name, date of birth and gender
 * are exactly what the NIDA lookup would return for their number — the same
 * rule §9 imposes on real registration.
 *
 * The book deliberately covers every state the customer list filters on:
 * completed and incomplete KYC, active/suspended/frozen, and the four approval
 * states — otherwise the filters have nothing to filter.
 */
final class CustomerSeeder extends Seeder
{
    private const int COUNT = 24;

    public function run(): void
    {
        $nida = app(NidaRegistry::class);
        $numbers = app(CustomerNumberGenerator::class);

        $branches = Branch::query()->where('is_head_office', false)->orderBy('id')->get();
        $categories = CustomerCategory::query()->orderBy('id')->get();
        $officer = User::query()->where('phone', '0754000005')->first()
            ?? User::query()->orderBy('id')->first();

        if ($branches->isEmpty() || $categories->isEmpty() || $officer === null) {
            return;
        }

        $regions = Region::query()->with('districts.wards.streets')->get();
        $now = Date::now();

        for ($i = 0; $i < self::COUNT; $i++) {
            /*
             * Spread deliberately. NidaRegistry's hash is the frontend's
             * 32-bit `hash * 31 + char`, so near-sequential numbers land on
             * near-identical hashes and the registry hands back the same name
             * several times over. Multiplying by a large prime and varying the
             * leading digits decorrelates them, which is what gives the seeded
             * book a realistic spread of people rather than four Mariam
             * Mbwanas.
             */
            $nidaNumber = sprintf(
                '19%02d%010d',
                70 + (($i * 7) % 30),
                (int) (100000 + $i * 104729) % 9999999999,
            );

            if (Customer::query()->where('nida_number', $nidaNumber)->exists()) {
                continue;
            }

            $identity = $nida->lookup($nidaNumber);
            $branch = $branches[$i % $branches->count()];
            $category = $categories[$i % $categories->count()];

            [$region, $district, $ward, $street] = $this->address($regions, $i);

            // A predictable spread rather than randomness, so the seeded book
            // is identical on every machine and the filter tests can assert
            // exact counts.
            $kycComplete = $i % 6 !== 5;
            $status = match (true) {
                $i % 11 === 10 => CustomerStatus::Frozen,
                $i % 7 === 6 => CustomerStatus::Suspended,
                default => CustomerStatus::Active,
            };

            $approval = match (true) {
                ! $category->requires_extra_approval => CustomerApprovalStatus::NotRequired,
                $i % 3 === 0 => CustomerApprovalStatus::Approved,
                $i % 3 === 1 => CustomerApprovalStatus::Pending,
                default => CustomerApprovalStatus::Rejected,
            };

            $customer = Customer::query()->create([
                'customer_number' => $numbers->next(),
                'nida_number' => $nidaNumber,

                'first_name' => $identity->firstName,
                'middle_name' => $identity->middleName,
                'last_name' => $identity->lastName,
                'dob' => $identity->dob,
                'gender' => $identity->gender,
                'phone' => sprintf('07%08d', 65000000 + $i * 137),

                'nida_verified_at' => $now->subDays(120 - $i),
                'otp_verified_at' => $now->subDays(120 - $i),
                'face_verified_at' => $kycComplete ? $now->subDays(120 - $i) : null,

                'marital_status' => $kycComplete
                    ? MaritalStatus::cases()[$i % count(MaritalStatus::cases())]
                    : null,

                'region_id' => $kycComplete ? $region?->getKey() : null,
                'district_id' => $kycComplete ? $district?->getKey() : null,
                'ward_id' => $kycComplete ? $ward?->getKey() : null,
                'street_id' => $kycComplete ? $street?->getKey() : null,
                'residence_type' => $kycComplete
                    ? ResidenceType::cases()[$i % count(ResidenceType::cases())]
                    : null,

                'customer_category_id' => $category->getKey(),
                'dynamic_form_data' => $this->dynamicDataFor($category, $i),
                'branch_id' => $branch->getKey(),

                'kyc_status' => KycStatus::Incomplete,
                'status' => $status,

                'approval_status' => $approval,
                'approved_by' => in_array($approval, [CustomerApprovalStatus::Approved, CustomerApprovalStatus::Rejected], true)
                    ? $officer->getKey()
                    : null,
                'approved_at' => in_array($approval, [CustomerApprovalStatus::Approved, CustomerApprovalStatus::Rejected], true)
                    ? $now->subDays(100 - $i)
                    : null,
                'rejection_reason' => $approval === CustomerApprovalStatus::Rejected
                    ? 'Business turnover could not be substantiated.'
                    : null,

                'created_by' => $officer->getKey(),
            ]);

            if ($kycComplete) {
                $customer->bankDetails()->create([
                    'bank_name' => ['CRDB Bank', 'NMB Bank', 'NBC Bank'][$i % 3],
                    'account_number' => sprintf('01J%010d', 500000 + $i * 331),
                    'account_name' => $customer->fullName(),
                    'phone_number' => $customer->phone,
                    'check_number' => $category->code === 'PUBLIC_SERVANT' ? sprintf('CHK%06d', 1000 + $i) : null,
                ]);
            }

            $customer->guarantors()->create([
                'name' => $nida->lookup($nidaNumber.'G')->firstName.' '.$nida->lookup($nidaNumber.'G')->lastName,
                'phone' => sprintf('07%08d', 71000000 + $i * 91),
                'nida_number' => null,
                'relationship' => GuarantorRelationship::cases()[$i % count(GuarantorRelationship::cases())],
                'address' => $ward?->name,
                'occupation' => 'Trader',
            ]);

            $customer->nextOfKin()->create([
                'name' => $nida->lookup($nidaNumber.'K')->firstName.' '.$customer->last_name,
                'relationship' => GuarantorRelationship::Spouse,
                'phone' => sprintf('07%08d', 72000000 + $i * 73),
                'address' => $ward?->name,
            ]);

            // Derived, never asserted — the same rule the API applies.
            $customer->load('bankDetails');
            app(\App\Domain\Customers\Services\KycEvaluator::class)->refresh($customer);
        }

        $this->seedGroups();
    }

    /**
     * The legacy group table, which has exactly one row in it.
     *
     * The Group List screen prints "Showing 1 to 1 of 1 entries" against a
     * single group named WAZURI. That is the whole legacy group book, and it
     * supersedes both the per-branch "<Branch> Wajasiriamali Group" this used
     * to invent and the thirty seeded groups the written brief asked for.
     *
     * No members are attached. The legacy Group List carries three columns —
     * S/NO., Group Name, Action — with no member count, leader, branch or
     * balance among them, so who belongs to WAZURI is not something any
     * captured screen says. Our schema keeps those columns; they stay empty
     * rather than being filled with a plausible committee.
     *
     * The branch is likewise unknown, so the group is attached to the first
     * legacy branch purely because `groups.branch_id` is NOT NULL. That is a
     * schema requirement showing through, not a fact about WAZURI.
     */
    private function seedGroups(): void
    {
        $branch = Branch::query()
            ->where('is_head_office', false)
            ->orderBy('id')
            ->first();

        if ($branch === null) {
            return;
        }

        foreach (LegacySource::groups() as $name) {
            Group::query()->firstOrCreate(
                ['branch_id' => $branch->getKey(), 'name' => $name],
                ['leader_customer_id' => null, 'status' => ActiveStatus::Active],
            );
        }
    }

    /**
     * Produces values satisfying the category's own required fields, so every
     * seeded customer would pass DynamicFormValidator.
     *
     * @return array<string, string|int|float|bool>
     */
    private function dynamicDataFor(CustomerCategory $category, int $index): array
    {
        $data = [];

        foreach ($category->dynamic_form_schema as $field) {
            $key = (string) ($field['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $data[$key] = match ((string) ($field['type'] ?? 'text')) {
                'number' => 25000 + $index * 1500,
                'date' => Date::now()->subYears(3)->toDateString(),
                default => match ($key) {
                    'motorcycle_registration_number' => sprintf('MC %03d ABC', 100 + $index),
                    'business_type' => ['Retail kiosk', 'Tailoring', 'Produce trading'][$index % 3],
                    'business_location' => 'Market Street',
                    'employer_name' => ['Ministry of Health', 'TANESCO', 'Vodacom Tanzania'][$index % 3],
                    'check_number' => sprintf('CHK%06d', 1000 + $index),
                    'account_number' => sprintf('01J%010d', 500000 + $index * 331),
                    'route' => 'Town — Market',
                    'collateral' => 'Household goods',
                    default => 'N/A',
                },
            };
        }

        return $data;
    }

    /**
     * Picks a full region → district → ward → street chain.
     *
     * @param \Illuminate\Support\Collection<int, Region> $regions
     * @return array{0: ?Region, 1: ?District, 2: ?Ward, 3: ?Street}
     */
    private function address(\Illuminate\Support\Collection $regions, int $index): array
    {
        if ($regions->isEmpty()) {
            return [null, null, null, null];
        }

        $region = $regions[$index % $regions->count()];
        $district = $region->districts[$index % max(1, $region->districts->count())] ?? null;
        $ward = $district?->wards[$index % max(1, $district->wards->count())] ?? null;
        $street = $ward?->streets[$index % max(1, $ward->streets->count())] ?? null;

        return [$region, $district, $ward, $street];
    }
}
