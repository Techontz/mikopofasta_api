<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\District;
use App\Models\Region;
use App\Models\Street;
use App\Models\Ward;
use Illuminate\Database\Seeder;

/**
 * Tanzanian administrative geography: region → district → ward → street.
 *
 * The five regions and their districts/wards/streets mirror the frontend's
 * seed data (lib/mock-data/{regions,districts,wards,streets}.ts) so the demo
 * narrative continues to work against the real API — the same Mwanza,
 * Kigoma, Kagera, Lindi and Mbeya, with the same real district and ward names.
 *
 * Idempotent throughout: re-seeding adds anything new without duplicating what
 * is already there.
 */
final class GeographySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->data() as $regionName => $districts) {
            $region = Region::query()->firstOrCreate(['name' => $regionName]);

            foreach ($districts as $districtName => $wards) {
                $district = District::query()->firstOrCreate([
                    'region_id' => $region->getKey(),
                    'name' => $districtName,
                ]);

                foreach ($wards as $wardName => $streets) {
                    $ward = Ward::query()->firstOrCreate([
                        'district_id' => $district->getKey(),
                        'name' => $wardName,
                    ]);

                    foreach ($streets as $streetName) {
                        Street::query()->firstOrCreate([
                            'ward_id' => $ward->getKey(),
                            'name' => $streetName,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * region => district => ward => [streets]
     *
     * @return array<string, array<string, array<string, list<string>>>>
     */
    private function data(): array
    {
        return [
            'Mwanza' => [
                'Nyamagana' => [
                    'Mirongo' => ['Mirongo Street', 'Kenyatta Road'],
                    'Isamilo' => ['Isamilo Hill Street'],
                ],
                'Ilemela' => [
                    'Buzuruga' => ['Buzuruga Main Street'],
                    'Kirumba' => ['Kirumba Market Road'],
                ],
            ],
            'Kigoma' => [
                'Kigoma Urban' => [
                    'Mwanga' => ['Mwanga Street'],
                    'Ujiji' => ['Ujiji Lakeside Road'],
                ],
                'Kasulu' => [
                    'Heru Juu' => ['Heru Juu Street'],
                ],
                // Kakonko district hosts the branch of the same name.
                'Kakonko' => [
                    'Kakonko' => ['Kakonko Main Street', 'Muhange Road'],
                ],
            ],
            'Kagera' => [
                'Bukoba Urban' => [
                    'Miembeni' => ['Miembeni Street'],
                    'Hamugembe' => ['Hamugembe Road'],
                ],
                'Muleba' => [
                    'Rubya' => ['Rubya Street'],
                ],
                // Missenyi district hosts the branch of the same name.
                'Missenyi' => [
                    'Bunazi' => ['Bunazi Street', 'Kyaka Road'],
                ],
            ],
            'Lindi' => [
                'Lindi Urban' => [
                    'Mikindani' => ['Mikindani Waterfront Road'],
                ],
                'Kilwa' => [
                    'Kivinje' => ['Kivinje Street'],
                ],
            ],
            'Mbeya' => [
                'Mbeya Urban' => [
                    'Forest' => ['Forest Street'],
                    'Iyunga' => ['Iyunga Road'],
                ],
                'Rungwe' => [
                    'Tukuyu' => ['Tukuyu Main Street'],
                ],
            ],
        ];
    }
}
