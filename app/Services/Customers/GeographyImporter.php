<?php

namespace App\Services\Customers;

use App\Models\District;
use App\Models\Region;
use App\Models\Ward;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Idempotent Tanzania geography importer (CSV header region,district,ward,street). Each row finds or creates its
 * region, district and ward; rows missing a level are rejected and reported. Regions match on a normalised name
 * (case, spaces and punctuation ignored) so "Dar es salaam" and "Dar-es-salaam" are the same region.
 */
class GeographyImporter
{
    /**
     * @var list<string>
     */
    public const REQUIRED_COLUMNS = ['region', 'district', 'ward'];

    /**
     * Import a CSV file.
     *
     * @return array{rows: int, imported: int, regionsCreated: int, districtsCreated: int, wardsCreated: int, rejected: list<array{line: int, reason: string}>}
     *
     * @throws InvalidArgumentException when the file cannot be read or the header lacks a required column
     */
    public function import(string $path): array
    {
        $handle = is_readable($path) ? fopen($path, 'r') : false;
        if ($handle === false) {
            throw new InvalidArgumentException("The geography file [{$path}] cannot be read.");
        }

        try {
            $header = fgetcsv($handle, escape: '');
            $columns = array_map(fn ($column): string => Str::lower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $column))), $header === false ? [] : $header);
            $missing = array_diff(self::REQUIRED_COLUMNS, $columns);
            if ($missing !== []) {
                throw new InvalidArgumentException('The file must have the header region,district,ward,street. Missing: '.implode(', ', $missing).'.');
            }
            $index = array_flip($columns);

            $summary = ['rows' => 0, 'imported' => 0, 'regionsCreated' => 0, 'districtsCreated' => 0, 'wardsCreated' => 0, 'rejected' => []];
            $regions = Region::query()->get(['id', 'name'])->mapWithKeys(fn (Region $region): array => [$this->regionKey($region->name) => $region->id])->all();
            $districts = [];
            $wards = [];
            $line = 1;

            while (($row = fgetcsv($handle, escape: '')) !== false) {
                $line++;
                if ($row === [null] || $row === []) {
                    continue;
                }
                $summary['rows']++;

                $values = [];
                foreach (self::REQUIRED_COLUMNS as $column) {
                    $values[$column] = $this->clean($row[$index[$column]] ?? '');
                }

                $problem = collect(self::REQUIRED_COLUMNS)->first(fn (string $column): bool => $values[$column] === '');
                if ($problem !== null) {
                    $summary['rejected'][] = ['line' => $line, 'reason' => ucfirst($problem).' is missing.'];

                    continue;
                }
                $tooLong = collect(self::REQUIRED_COLUMNS)->first(fn (string $column): bool => mb_strlen($values[$column]) > 255);
                if ($tooLong !== null) {
                    $summary['rejected'][] = ['line' => $line, 'reason' => ucfirst($tooLong).' is longer than 255 characters.'];

                    continue;
                }

                $regionKey = $this->regionKey($values['region']);
                if (! isset($regions[$regionKey])) {
                    $regions[$regionKey] = Region::query()->create(['name' => $values['region']])->id;
                    $summary['regionsCreated']++;
                }
                $regionId = $regions[$regionKey];

                $districtId = $districts[$regionId][$this->nameKey($values['district'])] ??= $this->district($regionId, $values['district'], $summary);
                $wardKey = $this->nameKey($values['ward']);
                if (! isset($wards[$districtId])) {
                    $wards[$districtId] = Ward::query()->where('district_id', $districtId)->pluck('name')->mapWithKeys(fn (string $name): array => [$this->nameKey($name) => true])->all();
                }
                if (! isset($wards[$districtId][$wardKey])) {
                    Ward::query()->create(['district_id' => $districtId, 'name' => $values['ward']]);
                    $wards[$districtId][$wardKey] = true;
                    $summary['wardsCreated']++;
                }

                $summary['imported']++;
            }

            return $summary;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Counts shown under Settings → Geography.
     *
     * @return array{regions: int, districts: int, wards: int}
     */
    public function counts(): array
    {
        return ['regions' => Region::query()->count(), 'districts' => District::query()->count(), 'wards' => Ward::query()->count()];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function district(int $regionId, string $name, array &$summary): int
    {
        $existing = District::query()->where('region_id', $regionId)->get(['id', 'name'])
            ->first(fn (District $district): bool => $this->nameKey($district->name) === $this->nameKey($name));
        if ($existing !== null) {
            return $existing->id;
        }

        $summary['districtsCreated']++;

        return District::query()->create(['region_id' => $regionId, 'name' => $name])->id;
    }

    private function clean(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    private function nameKey(string $name): string
    {
        return mb_strtolower($this->clean($name));
    }

    private function regionKey(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($name)));
    }
}
