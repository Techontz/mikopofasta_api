<?php

namespace App\Console\Commands;

use App\Services\Customers\GeographyImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Imports Tanzania Region → District → Ward from a CSV (header region,district,ward,street). Idempotent.
 */
class ImportGeography extends Command
{
    protected $signature = 'geography:import {path=database/data/tz-geography.csv : CSV path, absolute or relative to the application root}';

    protected $description = 'Import Tanzania regions, districts and wards from a CSV file (find-or-create, idempotent)';

    public function handle(GeographyImporter $importer): int
    {
        $path = (string) $this->argument('path');
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }

        try {
            $summary = $importer->import($path);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Read %d rows: %d imported, %d rejected. Created %d regions, %d districts, %d wards.',
            $summary['rows'], $summary['imported'], count($summary['rejected']), $summary['regionsCreated'], $summary['districtsCreated'], $summary['wardsCreated'],
        ));

        foreach ($summary['rejected'] as $rejected) {
            $this->warn("Line {$rejected['line']}: {$rejected['reason']}");
        }

        return self::SUCCESS;
    }
}
