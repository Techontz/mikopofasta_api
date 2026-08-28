<?php

declare(strict_types=1);

namespace App\Domain\Organization\Services;

use App\Domain\Organization\DTOs\GeographyImportResult;
use App\Models\District;
use App\Models\Region;
use App\Models\Street;
use App\Models\Ward;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SplFileObject;

/**
 * Loads Tanzania's administrative hierarchy from a CSV.
 *
 * WHY THIS EXISTS. Region → District → Ward → Street has always been the
 * schema, and the seed carries five regions, twelve districts, seventeen wards
 * and twenty streets — a demonstration subset. Registration now REQUIRES all
 * four levels to be chosen, so a branch outside those seventeen wards cannot
 * complete an address. The fix is to import the real register, not to let an
 * officer type a ward that nothing can search.
 *
 * NOTHING IS INVENTED HERE. This class contains no place names. It reads what
 * it is given, and an empty database plus no file means an empty database.
 *
 * ONE FILE, FOUR LEVELS. The columns are `region,district,ward,street`, and a
 * row may stop early: a region on its own is a valid row, a region and a
 * district is a valid row. That is also the shape every published register
 * comes in, so a downloaded file usually needs its header renamed and nothing
 * else. Per-level files would need four uploads in the right order and would
 * let somebody load wards before their districts existed.
 *
 * PARENTAGE IS STRUCTURAL, NOT CHECKED AFTER THE FACT. A ward is created under
 * the district on its own row, so a row naming a ward without a district
 * cannot produce an orphan — it is rejected. This is why the hierarchy cannot
 * be corrupted by a badly sorted file.
 *
 * SAFE TO RUN AGAIN. Every level is matched on (parent, name) and created only
 * if absent, so re-importing the same file creates nothing. It is how a
 * partially-failed import is finished: fix the rejected rows and re-run the
 * whole file.
 *
 * NAMES ARE MATCHED CASE-INSENSITIVELY and trimmed, because "Kigoma" and
 * "KIGOMA " in two different source files are one region, and creating both
 * would split every district beneath them.
 */
final class GeographyImporter
{
    /** The header this importer understands, in order. */
    public const array COLUMNS = ['region', 'district', 'ward', 'street'];

    /** Rows above this are refused outright rather than half-processed. */
    public const int MAX_ROWS = 200_000;

    /**
     * @param string $path A readable CSV, header row included.
     */
    public function import(string $path): GeographyImportResult
    {
        $created = ['regions' => 0, 'districts' => 0, 'wards' => 0, 'streets' => 0];
        $existing = ['regions' => 0, 'districts' => 0, 'wards' => 0, 'streets' => 0];
        $rejected = [];
        $rowsRead = 0;

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $header = null;

        /*
         * Caches keyed by the lower-cased path to a row — "kigoma|kakonko" —
         * so a file with four thousand streets in one ward does not run four
         * thousand identical lookups for that ward.
         */
        $cache = ['regions' => [], 'districts' => [], 'wards' => []];

        DB::transaction(function () use ($file, &$header, &$created, &$existing, &$rejected, &$rowsRead, &$cache): void {
            foreach ($file as $line => $row) {
                if (! is_array($row) || $row === [null]) {
                    continue;
                }

                if ($header === null) {
                    $header = $this->readHeader($row);

                    continue;
                }

                if ($rowsRead >= self::MAX_ROWS) {
                    $rejected[] = ['row' => $line + 1, 'reason' => 'File exceeds '.self::MAX_ROWS.' rows.'];

                    break;
                }

                $rowsRead++;
                $values = $this->normalise($row, $header);

                $error = $this->validate($values);

                if ($error !== null) {
                    $rejected[] = ['row' => $line + 1, 'reason' => $error];

                    continue;
                }

                $this->persist($values, $created, $existing, $cache);
            }
        });

        return new GeographyImportResult($created, $existing, $rejected, $rowsRead);
    }

    /**
     * @param list<string|null> $row
     * @return array<string, int> Column name => its position.
     */
    private function readHeader(array $row): array
    {
        $header = [];

        foreach ($row as $index => $name) {
            $key = strtolower(trim((string) $name));

            if (in_array($key, self::COLUMNS, true)) {
                $header[$key] = $index;
            }
        }

        if (! array_key_exists('region', $header)) {
            throw new InvalidArgumentException(
                'The CSV needs a header row with a `region` column. Recognised columns: '.implode(', ', self::COLUMNS).'.',
            );
        }

        return $header;
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $header
     * @return array<string, string|null>
     */
    private function normalise(array $row, array $header): array
    {
        $values = [];

        foreach (self::COLUMNS as $column) {
            $index = $header[$column] ?? null;
            $raw = $index === null ? null : ($row[$index] ?? null);
            $trimmed = trim((string) $raw);

            $values[$column] = $trimmed === '' ? null : $trimmed;
        }

        return $values;
    }

    /**
     * A level may only be named if every level above it is.
     *
     * @param array<string, string|null> $values
     */
    private function validate(array $values): ?string
    {
        if ($values['region'] === null) {
            return 'No region — every row must name one.';
        }

        if ($values['ward'] !== null && $values['district'] === null) {
            return sprintf('Ward "%s" has no district. A ward belongs to a district.', $values['ward']);
        }

        if ($values['street'] !== null && $values['ward'] === null) {
            return sprintf('Street "%s" has no ward. A street belongs to a ward.', $values['street']);
        }

        foreach (self::COLUMNS as $column) {
            if ($values[$column] !== null && mb_strlen($values[$column]) > 120) {
                return ucfirst($column).' name is longer than 120 characters.';
            }
        }

        return null;
    }

    /**
     * @param array<string, string|null> $values
     * @param array<string, int> $created
     * @param array<string, int> $existing
     * @param array<string, array<string, int>> $cache
     */
    private function persist(array $values, array &$created, array &$existing, array &$cache): void
    {
        $regionId = $this->resolve(
            Region::class, $cache['regions'], $this->key($values['region']),
            ['name' => $values['region']], $created['regions'], $existing['regions'],
        );

        if ($values['district'] === null) {
            return;
        }

        $districtId = $this->resolve(
            District::class, $cache['districts'], $this->key($values['region'], $values['district']),
            ['region_id' => $regionId, 'name' => $values['district']], $created['districts'], $existing['districts'],
        );

        if ($values['ward'] === null) {
            return;
        }

        $wardId = $this->resolve(
            Ward::class, $cache['wards'], $this->key($values['region'], $values['district'], $values['ward']),
            ['district_id' => $districtId, 'name' => $values['ward']], $created['wards'], $existing['wards'],
        );

        if ($values['street'] === null) {
            return;
        }

        /* Streets are not cached: they are the leaves, each appears once, and
           a cache of every street in the country would be the memory problem
           the caches above exist to avoid. */
        $this->resolveUncached(
            Street::class,
            ['ward_id' => $wardId, 'name' => $values['street']],
            $created['streets'],
            $existing['streets'],
        );
    }

    /**
     * @param class-string<Region|District|Ward> $model
     * @param array<string, int> $cache
     * @param array<string, mixed> $attributes
     */
    private function resolve(string $model, array &$cache, string $key, array $attributes, int &$created, int &$existing): int
    {
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $id = $this->resolveUncached($model, $attributes, $created, $existing);
        $cache[$key] = $id;

        return $id;
    }

    /**
     * Finds the row or creates it — the whole of what makes a re-import a
     * no-op. Matched case-insensitively so two spellings do not become two
     * places, and the FIRST spelling seen is the one kept.
     *
     * @param class-string<Region|District|Ward|Street> $model
     * @param array<string, mixed> $attributes
     */
    private function resolveUncached(string $model, array $attributes, int &$created, int &$existing): int
    {
        $name = $attributes['name'];
        unset($attributes['name']);

        $query = $model::query();

        foreach ($attributes as $column => $value) {
            $query->where($column, $value);
        }

        $found = $query->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($found !== null) {
            $existing++;

            return (int) $found->getKey();
        }

        /* Built and filled rather than ->create(): the four models are one
           union to this method, and Eloquent's create() is generic over a
           single model class. fill() takes the same array and keeps the
           mass-assignment guard, which is the part that matters. */
        $row = new $model;
        $row->fill([...$attributes, 'name' => $name]);
        $row->save();
        $created++;

        return (int) $row->getKey();
    }

    private function key(string ...$parts): string
    {
        return mb_strtolower(implode('|', $parts));
    }
}
