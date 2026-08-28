<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Models\District;
use App\Models\Region;
use App\Models\Street;
use App\Models\Ward;
use Illuminate\Http\UploadedFile;

/**
 * Administration → Master Data → Geography.
 *
 * Registration now REQUIRES all four address levels to be chosen, and the seed
 * carries a demonstration subset — five regions, twelve districts, seventeen
 * wards. A branch outside those wards cannot complete an address until the
 * real register is loaded, and the answer is to import it rather than let an
 * officer type a ward nothing can search.
 *
 * NOT ONE PLACE NAME IN THE APPLICATION. Every fixture below writes its own
 * CSV; the importer contains no geography, and neither does any test here
 * assert that a particular Tanzanian ward exists. What these prove is the
 * MECHANISM: parentage, idempotence, and refusal.
 */
beforeEach(function (): void {
    seedCustomerFoundation();
});

/** A CSV built in the test, never a checked-in dataset. */
function geographyCsv(string $body, string $header = "region,district,ward,street\n"): UploadedFile
{
    return UploadedFile::fake()->createWithContent('register.csv', $header.$body);
}

function importAs(string $role, UploadedFile $file)
{
    officerAt('Kakonko', constant(RoleName::class.'::'.$role));

    return test()->post('/api/v1/master-data/geography/import', ['file' => $file]);
}

/* -------------------------------------------------------------------------
 | Loading the register
 |------------------------------------------------------------------------- */

describe('importing', function (): void {
    it('creates the whole chain from one row', function (): void {
        $before = Region::query()->count();

        $response = importAs('Admin', geographyCsv("Testland,Testville,Testward,Test Street\n"))
            ->assertOk();

        expect($response->json('data.created.regions'))->toBe(1)
            ->and($response->json('data.created.districts'))->toBe(1)
            ->and($response->json('data.created.wards'))->toBe(1)
            ->and($response->json('data.created.streets'))->toBe(1)
            ->and(Region::query()->count())->toBe($before + 1);

        /* And the chain actually hangs together, rather than four rows each
           pointing at nothing. */
        $region = Region::query()->where('name', 'Testland')->sole();
        $district = District::query()->where('name', 'Testville')->sole();
        $ward = Ward::query()->where('name', 'Testward')->sole();
        $street = Street::query()->where('name', 'Test Street')->sole();

        expect($district->region_id)->toBe($region->id)
            ->and($ward->district_id)->toBe($district->id)
            ->and($street->ward_id)->toBe($ward->id);
    });

    /* A register is naturally ragged: some rows stop at the district, some go
       all the way down. Both are valid. */
    it('accepts a row that stops before the bottom', function (): void {
        $response = importAs('Admin', geographyCsv(
            "Alphaland,,,\n".
            "Betaland,Betaville,,\n".
            "Gammaland,Gammaville,Gammaward,\n",
        ))->assertOk();

        expect($response->json('data.created.regions'))->toBe(3)
            ->and($response->json('data.created.districts'))->toBe(2)
            ->and($response->json('data.created.wards'))->toBe(1)
            ->and($response->json('data.created.streets'))->toBe(0)
            ->and($response->json('data.rejectedCount'))->toBe(0);
    });

    it('adds a second street to a ward without duplicating its parents', function (): void {
        importAs('Admin', geographyCsv(
            "Testland,Testville,Testward,First Street\n".
            "Testland,Testville,Testward,Second Street\n",
        ))->assertOk();

        expect(Region::query()->where('name', 'Testland')->count())->toBe(1)
            ->and(Ward::query()->where('name', 'Testward')->count())->toBe(1)
            ->and(Street::query()->where('name', 'First Street')->count())->toBe(1)
            ->and(Street::query()->where('name', 'Second Street')->count())->toBe(1);
    });

    /* Two files spelling a region differently must not split every district
       beneath it into two trees. */
    it('treats names case-insensitively so one place stays one row', function (): void {
        importAs('Admin', geographyCsv("Testland,Testville,Testward,Test Street\n"))->assertOk();

        $response = importAs('Admin', geographyCsv("TESTLAND, testville ,TestWard,TEST STREET\n"))
            ->assertOk();

        expect($response->json('data.created.regions'))->toBe(0)
            ->and($response->json('data.existing.regions'))->toBe(1)
            ->and(Region::query()->where('name', 'Testland')->count())->toBe(1);
    });
});

/* -------------------------------------------------------------------------
 | Safe to run again
 |------------------------------------------------------------------------- */

describe('running it twice', function (): void {
    it('creates nothing the second time', function (): void {
        $csv = "Testland,Testville,Testward,Test Street\nTestland,Testville,Testward,Second Street\n";

        importAs('Admin', geographyCsv($csv))->assertOk();

        $counts = [
            Region::query()->count(), District::query()->count(),
            Ward::query()->count(), Street::query()->count(),
        ];

        $again = importAs('Admin', geographyCsv($csv))->assertOk();

        expect($again->json('data.created'))->toBe([
            'regions' => 0, 'districts' => 0, 'wards' => 0, 'streets' => 0,
        ])
            ->and($again->json('data.existing.streets'))->toBe(2)
            ->and([
                Region::query()->count(), District::query()->count(),
                Ward::query()->count(), Street::query()->count(),
            ])->toBe($counts);
    });

    /* How a partially-rejected import is finished: fix the bad rows and
       re-import the whole file. */
    it('adds only what is new when the file grows', function (): void {
        importAs('Admin', geographyCsv("Testland,Testville,Testward,First Street\n"))->assertOk();

        $response = importAs('Admin', geographyCsv(
            "Testland,Testville,Testward,First Street\n".
            "Testland,Testville,Testward,Second Street\n",
        ))->assertOk();

        expect($response->json('data.created.streets'))->toBe(1)
            ->and($response->json('data.existing.streets'))->toBe(1);
    });
});

/* -------------------------------------------------------------------------
 | Refusing what would corrupt the hierarchy
 |------------------------------------------------------------------------- */

describe('rejection', function (): void {
    it('refuses a ward with no district', function (): void {
        $response = importAs('Admin', geographyCsv("Testland,,Orphanward,\n"))->assertOk();

        expect($response->json('data.rejectedCount'))->toBe(1)
            ->and($response->json('data.rejected.0.reason'))->toContain('belongs to a district')
            ->and(Ward::query()->where('name', 'Orphanward')->exists())->toBeFalse();
    });

    it('refuses a street with no ward', function (): void {
        $response = importAs('Admin', geographyCsv("Testland,Testville,,Orphan Street\n"))->assertOk();

        expect($response->json('data.rejectedCount'))->toBe(1)
            ->and($response->json('data.rejected.0.reason'))->toContain('belongs to a ward')
            ->and(Street::query()->where('name', 'Orphan Street')->exists())->toBeFalse();
    });

    it('refuses a row with no region', function (): void {
        $response = importAs('Admin', geographyCsv(",Testville,Testward,Test Street\n"))->assertOk();

        expect($response->json('data.rejectedCount'))->toBe(1)
            ->and($response->json('data.rejected.0.reason'))->toContain('must name one');
    });

    /* One bad row must not cost the file. */
    it('keeps the good rows and names the bad ones', function (): void {
        $response = importAs('Admin', geographyCsv(
            "Testland,Testville,Testward,Good Street\n".
            "Testland,,Orphanward,\n".
            "Testland,Testville,Testward,Another Street\n",
        ))->assertOk();

        expect($response->json('data.created.streets'))->toBe(2)
            ->and($response->json('data.rejectedCount'))->toBe(1)
            ->and($response->json('data.rejected.0.row'))->toBe(3);
    });

    it('refuses a file with no region column at all', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $this->post('/api/v1/master-data/geography/import', [
            'file' => UploadedFile::fake()->createWithContent('wrong.csv', "province,area\nX,Y\n"),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
    });

    it('refuses a name longer than the column holds', function (): void {
        $response = importAs('Admin', geographyCsv('Testland,'.str_repeat('x', 121).",,\n"))->assertOk();

        expect($response->json('data.rejectedCount'))->toBe(1)
            ->and($response->json('data.rejected.0.reason'))->toContain('longer than 120');
    });
});

/* -------------------------------------------------------------------------
 | Who may do it
 |------------------------------------------------------------------------- */

describe('authorization', function (): void {
    /* Reference data decides what an address can say. An officer who could add
       a ward could make one up. */
    it('refuses an import from a role without admin.org_settings', function (): void {
        importAs('LoanOfficer', geographyCsv("Testland,Testville,Testward,Test Street\n"))
            ->assertForbidden();

        expect(Region::query()->where('name', 'Testland')->exists())->toBeFalse();
    });

    it('refuses an unauthenticated import', function (): void {
        $this->post('/api/v1/master-data/geography/import', [
            'file' => geographyCsv("Testland,,,\n"),
        ])->assertUnauthorized();
    });

    it('reports what is on file to an administrator', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $this->getJson('/api/v1/master-data/geography')
            ->assertOk()
            ->assertJsonStructure(['data' => ['regions', 'districts', 'wards', 'streets', 'columns', 'maxRows']]);
    });

    it('records who ran the import', function (): void {
        importAs('Admin', geographyCsv("Testland,,,\n"))->assertOk();

        expect(DB::table('audit_logs')->where('action', 'GEOGRAPHY_IMPORTED')->exists())->toBeTrue();
    });
});
